<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;

class RepasseMpJobRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureTableExists();
    }

    public function getMeta(string $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT meta_json FROM repasse_mp_jobs WHERE job_id = :job_id LIMIT 1');
        $stmt->execute([':job_id' => $jobId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function saveMeta(string $jobId, array $meta): void
    {
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Falha ao serializar metadados do job.');
        }

        if ($this->isPgsql()) {
            $sql = "INSERT INTO repasse_mp_jobs (job_id, status, meta_json, created_at, updated_at)
                    VALUES (:job_id, :status, :meta_json, NOW(), NOW())
                    ON CONFLICT (job_id) DO UPDATE
                    SET status = EXCLUDED.status,
                        meta_json = EXCLUDED.meta_json,
                        updated_at = NOW()";
        } else {
            $sql = "INSERT INTO repasse_mp_jobs (job_id, status, meta_json, created_at, updated_at)
                    VALUES (:job_id, :status, :meta_json, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        meta_json = VALUES(meta_json),
                        updated_at = NOW()";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':job_id' => $jobId,
            ':status' => (string) ($meta['status'] ?? 'unknown'),
            ':meta_json' => $json,
        ]);
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

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
