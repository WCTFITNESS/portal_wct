<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Empresas/certificados A1 (cada uma com seu controle de NSU) e documentos da Distribuição DF-e de CT-e.
 */
class SefazDfeRepository
{
    private const PROFILE_COLUMNS = [
        'apelido', 'cnpj', 'uf_autor', 'cert_blob', 'cert_subject', 'cert_cnpj', 'cert_valid_to',
        'ult_nsu', 'max_nsu', 'last_sync_at', 'next_sync_at', 'last_status',
        'nfe_ult_nsu', 'nfe_max_nsu', 'nfe_last_sync_at', 'nfe_next_sync_at', 'nfe_last_status',
    ];

    private const DOC_COLUMNS = [
        'settings_id', 'nsu', 'schema_name', 'tipo', 'chave', 'numero', 'serie', 'modelo', 'dh_emi',
        'emit_cnpj', 'emit_nome', 'rem_cnpj', 'rem_nome', 'dest_nome', 'toma_cnpj',
        'valor', 'tp_evento', 'desc_evento', 'xml',
    ];

    private ?bool $tablesReady = null;

    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listProfiles(): array
    {
        $this->ensureTables();

        return $this->pdo->query('SELECT * FROM sefaz_dfe_settings ORDER BY id ASC')->fetchAll();
    }

    public function getProfile(int $id): ?array
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare('SELECT * FROM sefaz_dfe_settings WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $fields */
    public function createProfile(array $fields): int
    {
        $this->ensureTables();
        $fields = array_intersect_key($fields, array_flip(self::PROFILE_COLUMNS));
        $cols = array_keys($fields);
        $params = [];
        foreach ($fields as $col => $value) {
            $params[':' . $col] = $value;
        }
        $sql = 'INSERT INTO sefaz_dfe_settings (' . implode(', ', array_merge($cols, ['updated_at'])) . ')
                VALUES (' . implode(', ', array_merge(array_keys($params), ['NOW()'])) . ')';

        if ($this->isPgsql()) {
            $stmt = $this->pdo->prepare($sql . ' RETURNING id');
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        }
        $this->pdo->prepare($sql)->execute($params);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $fields */
    public function updateProfile(int $id, array $fields): void
    {
        $this->ensureTables();
        $fields = array_intersect_key($fields, array_flip(self::PROFILE_COLUMNS));
        if ($fields === []) {
            return;
        }
        $params = [':id' => $id];
        foreach ($fields as $col => $value) {
            $params[':' . $col] = $value;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($fields)));
        $this->pdo->prepare('UPDATE sefaz_dfe_settings SET ' . $sets . ', updated_at = NOW() WHERE id = :id')->execute($params);
    }

    public function deleteProfile(int $id): void
    {
        $this->ensureTables();
        $this->pdo->prepare('DELETE FROM sefaz_dfe_cte_docs WHERE settings_id = :id')->execute([':id' => $id]);
        $this->pdo->prepare('DELETE FROM sefaz_dfe_settings WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Grava o documento se o NSU ainda não existir para a empresa. Retorna true quando inseriu.
     *
     * @param array<string, mixed> $doc
     */
    public function insertDocument(int $profileId, array $doc): bool
    {
        $this->ensureTables();
        $exists = $this->pdo->prepare('SELECT 1 FROM sefaz_dfe_cte_docs WHERE settings_id = :p AND nsu = :nsu');
        $exists->execute([':p' => $profileId, ':nsu' => (string) $doc['nsu']]);
        if ($exists->fetchColumn()) {
            return false;
        }

        $doc['settings_id'] = $profileId;
        $params = [];
        foreach (self::DOC_COLUMNS as $col) {
            $params[':' . $col] = $doc[$col] ?? null;
        }
        $this->pdo->prepare(
            'INSERT INTO sefaz_dfe_cte_docs (' . implode(', ', self::DOC_COLUMNS) . ', created_at)
             VALUES (' . implode(', ', array_keys($params)) . ', NOW())'
        )->execute($params);

        return true;
    }

    /**
     * @param array{de?: string, ate?: string, busca?: string, tipo?: string, empresa?: string|int} $filters
     * @return list<array<string, mixed>>
     */
    public function listDocuments(array $filters, int $limit = 500): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->pdo->prepare(
            'SELECT id, settings_id, nsu, schema_name, tipo, chave, numero, serie, modelo, dh_emi, emit_cnpj, emit_nome,
                    rem_cnpj, rem_nome, dest_nome, toma_cnpj, valor, tp_evento, desc_evento, created_at
             FROM sefaz_dfe_cte_docs' . $where . '
             ORDER BY dh_emi DESC, id DESC
             LIMIT ' . max(1, min(5000, $limit))
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array{de?: string, ate?: string, busca?: string, tipo?: string, empresa?: string|int} $filters */
    public function countDocuments(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sefaz_dfe_cte_docs' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{de?: string, ate?: string, busca?: string, tipo?: string, empresa?: string|int} $filters
     * @return list<int>
     */
    public function findIds(array $filters, int $limit = 5000): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM sefaz_dfe_cte_docs' . $where
            . ' ORDER BY dh_emi DESC, id DESC LIMIT ' . max(1, min(5000, $limit))
        );
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findXmlByIds(array $ids): array
    {
        $this->ensureTables();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, chave, nsu, tipo, tp_evento, xml FROM sefaz_dfe_cte_docs
             WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    /** @return list<array{id: int, settings_id: ?int, nsu: string, xml: string}> */
    public function listXmlAfter(int $afterId, int $limit): array
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare(
            'SELECT id, settings_id, nsu, xml FROM sefaz_dfe_cte_docs WHERE id > :id ORDER BY id ASC LIMIT ' . max(1, min(1000, $limit))
        );
        $stmt->execute([':id' => $afterId]);

        return $stmt->fetchAll();
    }

    /**
     * @param array{de?: string, ate?: string, busca?: string, tipo?: string, empresa?: string|int} $filters
     * @return array{0: string, 1: array<string, string|int>}
     */
    private function buildWhere(array $filters): array
    {
        $this->ensureTables();
        $conds = [];
        $params = [];

        $empresa = (int) ($filters['empresa'] ?? 0);
        if ($empresa > 0) {
            $conds[] = 'settings_id = :empresa';
            $params[':empresa'] = $empresa;
        }

        $de = trim((string) ($filters['de'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) {
            $conds[] = 'dh_emi >= :de';
            $params[':de'] = $de . ' 00:00:00';
        }
        $ate = trim((string) ($filters['ate'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
            $conds[] = 'dh_emi <= :ate';
            $params[':ate'] = $ate . ' 23:59:59';
        }

        $tipo = (string) ($filters['tipo'] ?? '');
        if (in_array($tipo, ['cte', 'evento'], true)) {
            $conds[] = 'tipo = :tipo';
            $params[':tipo'] = $tipo;
        }

        $busca = trim((string) ($filters['busca'] ?? ''));
        if ($busca !== '') {
            $like = '%' . mb_strtolower($busca) . '%';
            $conds[] = '(LOWER(COALESCE(emit_nome, \'\')) LIKE :b1 OR LOWER(COALESCE(rem_nome, \'\')) LIKE :b2
                         OR LOWER(COALESCE(dest_nome, \'\')) LIKE :b3 OR COALESCE(chave, \'\') LIKE :b4
                         OR COALESCE(numero, \'\') = :b5 OR COALESCE(emit_cnpj, \'\') LIKE :b6)';
            $params[':b1'] = $like;
            $params[':b2'] = $like;
            $params[':b3'] = $like;
            $params[':b4'] = $like;
            $params[':b5'] = $busca;
            $params[':b6'] = $like;
        }

        return [$conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds), $params];
    }

    private function ensureTables(): void
    {
        if ($this->tablesReady === true) {
            return;
        }

        $pg = $this->isPgsql();
        $id = $pg ? 'id BIGSERIAL PRIMARY KEY' : 'id INT AUTO_INCREMENT PRIMARY KEY';
        $ts = $pg ? 'TIMESTAMP' : 'DATETIME';
        $bigText = $pg ? 'TEXT' : 'LONGTEXT';
        $money = $pg ? 'NUMERIC(15,2)' : 'DECIMAL(15,2)';
        $engine = $pg ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS sefaz_dfe_settings (
                {$id},
                apelido VARCHAR(100) NULL,
                cnpj VARCHAR(14) NOT NULL DEFAULT '',
                uf_autor VARCHAR(2) NOT NULL DEFAULT '42',
                cert_blob {$bigText} NULL,
                cert_subject VARCHAR(255) NULL,
                cert_cnpj VARCHAR(14) NULL,
                cert_valid_to {$ts} NULL,
                ult_nsu VARCHAR(15) NOT NULL DEFAULT '0',
                max_nsu VARCHAR(15) NOT NULL DEFAULT '0',
                last_sync_at {$ts} NULL,
                next_sync_at {$ts} NULL,
                last_status VARCHAR(500) NULL,
                updated_at {$ts} NOT NULL
            ){$engine}"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS sefaz_dfe_cte_docs (
                {$id},
                settings_id BIGINT NULL,
                nsu VARCHAR(15) NOT NULL,
                schema_name VARCHAR(60) NOT NULL DEFAULT '',
                tipo VARCHAR(20) NOT NULL DEFAULT 'outro',
                chave VARCHAR(44) NULL,
                numero VARCHAR(20) NULL,
                serie VARCHAR(5) NULL,
                modelo VARCHAR(5) NULL,
                dh_emi {$ts} NULL,
                emit_cnpj VARCHAR(14) NULL,
                emit_nome VARCHAR(255) NULL,
                rem_cnpj VARCHAR(14) NULL,
                rem_nome VARCHAR(255) NULL,
                dest_nome VARCHAR(255) NULL,
                toma_cnpj VARCHAR(14) NULL,
                valor {$money} NULL,
                tp_evento VARCHAR(10) NULL,
                desc_evento VARCHAR(120) NULL,
                xml {$bigText} NOT NULL,
                created_at {$ts} NOT NULL
            ){$engine}"
        );

        // Tabelas criadas pela versão de um certificado só.
        if (!$this->hasColumn('sefaz_dfe_settings', 'apelido')) {
            $this->pdo->exec('ALTER TABLE sefaz_dfe_settings ADD COLUMN apelido VARCHAR(100) NULL');
        }
        $nfeColumns = [
            'nfe_ult_nsu' => "VARCHAR(15) NOT NULL DEFAULT '0'",
            'nfe_max_nsu' => "VARCHAR(15) NOT NULL DEFAULT '0'",
            'nfe_last_sync_at' => "{$ts} NULL",
            'nfe_next_sync_at' => "{$ts} NULL",
            'nfe_last_status' => 'VARCHAR(500) NULL',
        ];
        foreach ($nfeColumns as $column => $definition) {
            if (!$this->hasColumn('sefaz_dfe_settings', $column)) {
                $this->pdo->exec("ALTER TABLE sefaz_dfe_settings ADD COLUMN {$column} {$definition}");
            }
        }
        if (!$this->hasColumn('sefaz_dfe_cte_docs', 'settings_id')) {
            $this->pdo->exec('ALTER TABLE sefaz_dfe_cte_docs ADD COLUMN settings_id BIGINT NULL');
            $this->pdo->exec('UPDATE sefaz_dfe_cte_docs SET settings_id = (SELECT MIN(id) FROM sefaz_dfe_settings) WHERE settings_id IS NULL');
        }
        if ($this->hasIndex('uq_sefaz_dfe_cte_docs_nsu')) {
            $this->pdo->exec($pg ? 'DROP INDEX uq_sefaz_dfe_cte_docs_nsu' : 'DROP INDEX uq_sefaz_dfe_cte_docs_nsu ON sefaz_dfe_cte_docs');
        }

        $indexes = [
            'uq_sefaz_dfe_cte_docs_perfil_nsu' => 'CREATE UNIQUE INDEX uq_sefaz_dfe_cte_docs_perfil_nsu ON sefaz_dfe_cte_docs (settings_id, nsu)',
            'idx_sefaz_dfe_cte_docs_dh_emi' => 'CREATE INDEX idx_sefaz_dfe_cte_docs_dh_emi ON sefaz_dfe_cte_docs (dh_emi)',
            'idx_sefaz_dfe_cte_docs_chave' => 'CREATE INDEX idx_sefaz_dfe_cte_docs_chave ON sefaz_dfe_cte_docs (chave)',
        ];
        foreach ($indexes as $name => $sql) {
            if (!$this->hasIndex($name)) {
                $this->pdo->exec($sql);
            }
        }

        $this->tablesReady = true;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $schema = $this->isPgsql() ? 'current_schema()' : 'DATABASE()';
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM information_schema.columns WHERE table_schema = {$schema} AND table_name = :t AND column_name = :c"
        );
        $stmt->execute([':t' => $table, ':c' => $column]);

        return (bool) $stmt->fetchColumn();
    }

    private function hasIndex(string $name): bool
    {
        if ($this->isPgsql()) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = :n');
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = \'sefaz_dfe_cte_docs\' AND index_name = :n LIMIT 1'
            );
        }
        $stmt->execute([':n' => $name]);

        return (bool) $stmt->fetchColumn();
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
