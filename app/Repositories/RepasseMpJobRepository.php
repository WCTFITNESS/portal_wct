<?php

declare(strict_types=1);

namespace App\Repositories;

use JsonException;
use PDO;
use RuntimeException;

class RepasseMpJobRepository
{
    private ?bool $hasSourceRowsColumn = null;

    public function __construct(private PDO $pdo)
    {
        $this->ensureTableExists();
        $this->ensureSourceRowsColumnExists();
    }

    /**
     * Carrega meta + linhas da planilha (coluna dedicada ou legado em meta_json).
     *
     * @return array<string, mixed>|null
     */
    public function getMeta(string $jobId, bool $includeRows = false): ?array
    {
        if (!$this->hasSourceRowsColumn()) {
            return $this->getMetaLegacy($jobId);
        }

        $stmt = $this->pdo->prepare(
            'SELECT meta_json, source_rows_json FROM repasse_mp_jobs WHERE job_id = :job_id LIMIT 1'
        );
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $metaJson = $row['meta_json'] ?? '';
        if (!is_string($metaJson) || trim($metaJson) === '') {
            return null;
        }

        try {
            $meta = json_decode($metaJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($meta)) {
            return null;
        }

        if ($includeRows) {
            $sourceRaw = $row['source_rows_json'] ?? null;
            if (is_string($sourceRaw) && $sourceRaw !== '') {
                try {
                    $rows = json_decode($sourceRaw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($rows)) {
                        $meta['rows'] = $rows;
                    }
                } catch (JsonException) {
                    // mantém meta sem rows
                }
            } elseif (isset($meta['rows']) && is_array($meta['rows']) && $meta['rows'] !== []) {
                $this->migrateInlineRowsToDedicatedColumn($jobId, $meta);
            }
        }

        return $meta;
    }

    /**
     * Cria job: progresso em meta_json, planilha uma única vez em source_rows_json.
     *
     * @param array<string, mixed> $progressMeta sem chave "rows"
     * @param list<array<int, mixed>>|array<int, array<int, mixed>> $rows
     */
    public function insertJob(string $jobId, array $progressMeta, array $rows): void
    {
        $metaJson = json_encode($progressMeta, JSON_UNESCAPED_UNICODE);
        $rowsJson = json_encode($rows, JSON_UNESCAPED_UNICODE);
        if ($metaJson === false || $rowsJson === false) {
            throw new RuntimeException('Falha ao serializar job Repasse MP.');
        }

        $status = (string) ($progressMeta['status'] ?? 'unknown');

        if ($this->isPgsql()) {
            $sql = 'INSERT INTO repasse_mp_jobs (job_id, status, meta_json, source_rows_json, created_at, updated_at)
                    VALUES (:job_id, :status, :meta_json, :source_rows_json, NOW(), NOW())';
        } else {
            $sql = 'INSERT INTO repasse_mp_jobs (job_id, status, meta_json, source_rows_json, created_at, updated_at)
                    VALUES (:job_id, :status, :meta_json, :source_rows_json, NOW(), NOW())';
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':job_id' => $jobId,
            ':status' => $status,
            ':meta_json' => $metaJson,
            ':source_rows_json' => $rowsJson,
        ]);
    }

    /**
     * Atualiza apenas progresso (cursor, matches, status). Não altera source_rows_json.
     *
     * @param array<string, mixed> $progressMeta sem chave "rows"
     */
    public function updateJobProgress(string $jobId, array $progressMeta): void
    {
        if (array_key_exists('rows', $progressMeta)) {
            unset($progressMeta['rows']);
        }

        $metaJson = json_encode($progressMeta, JSON_UNESCAPED_UNICODE);
        if ($metaJson === false) {
            throw new RuntimeException('Falha ao serializar metadados do job.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE repasse_mp_jobs SET status = :status, meta_json = :meta_json, updated_at = NOW() WHERE job_id = :job_id'
        );
        $stmt->execute([
            ':job_id' => $jobId,
            ':status' => (string) ($progressMeta['status'] ?? 'unknown'),
            ':meta_json' => $metaJson,
        ]);
    }

    public function clearSourceRows(string $jobId): void
    {
        if (!$this->hasSourceRowsColumn()) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE repasse_mp_jobs SET source_rows_json = NULL, updated_at = NOW() WHERE job_id = :job_id'
        );
        $stmt->execute([':job_id' => $jobId]);
    }

    private function getMetaLegacy(string $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT meta_json FROM repasse_mp_jobs WHERE job_id = :job_id LIMIT 1');
        $stmt->execute([':job_id' => $jobId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Move rows de dentro de meta_json para source_rows_json (jobs criados antes desta otimização).
     *
     * @param array<string, mixed> $meta referência: rows removidas do JSON inline e gravadas na coluna dedicada
     */
    private function migrateInlineRowsToDedicatedColumn(string $jobId, array &$meta): void
    {
        $rows = $meta['rows'];
        if (!is_array($rows) || $rows === []) {
            return;
        }

        unset($meta['rows']);
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $rowsJson = json_encode($rows, JSON_UNESCAPED_UNICODE);
        if ($metaJson === false || $rowsJson === false) {
            $meta['rows'] = $rows;

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE repasse_mp_jobs SET meta_json = :meta_json, source_rows_json = :source_rows_json, updated_at = NOW() WHERE job_id = :job_id'
        );
        $stmt->execute([
            ':job_id' => $jobId,
            ':meta_json' => $metaJson,
            ':source_rows_json' => $rowsJson,
        ]);

        $meta['rows'] = $rows;
    }

    private function ensureTableExists(): void
    {
        if ($this->isPgsql()) {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS repasse_mp_jobs (
                    job_id VARCHAR(32) PRIMARY KEY,
                    status VARCHAR(30) NOT NULL,
                    meta_json TEXT NOT NULL,
                    created_at TIMESTAMP NOT NULL,
                    updated_at TIMESTAMP NOT NULL
                )'
            );

            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS repasse_mp_jobs (
                job_id VARCHAR(32) PRIMARY KEY,
                status VARCHAR(30) NOT NULL,
                meta_json LONGTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function ensureSourceRowsColumnExists(): void
    {
        if ($this->hasSourceRowsColumn()) {
            return;
        }

        if ($this->isPgsql()) {
            $this->pdo->exec('ALTER TABLE repasse_mp_jobs ADD COLUMN source_rows_json TEXT DEFAULT NULL');
        } else {
            $this->pdo->exec('ALTER TABLE repasse_mp_jobs ADD COLUMN source_rows_json LONGTEXT NULL');
        }
        $this->hasSourceRowsColumn = true;
    }

    private function hasSourceRowsColumn(): bool
    {
        if ($this->hasSourceRowsColumn !== null) {
            return $this->hasSourceRowsColumn;
        }

        if ($this->isPgsql()) {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = 'repasse_mp_jobs'
                   AND column_name = 'source_rows_json'"
            );
        } else {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'repasse_mp_jobs'
                   AND column_name = 'source_rows_json'"
            );
        }

        $this->hasSourceRowsColumn = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasSourceRowsColumn;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
