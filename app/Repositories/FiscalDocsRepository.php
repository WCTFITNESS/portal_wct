<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Central de documentos fiscais: um registro por NF-e / CT-e / evento, venha da SEFAZ, do Mercado Livre ou da Rock.IT.
 * O resumo de NF-e (antes da Ciência da Operação) é substituído pelo XML completo quando ele chega.
 */
class FiscalDocsRepository
{
    private const COLUMNS = [
        'dedupe_key', 'origem', 'settings_id', 'nsu', 'tipo', 'completo', 'propria', 'modelo', 'chave', 'numero', 'serie',
        'dh_emi', 'emit_cnpj', 'emit_nome', 'dest_cnpj', 'dest_nome', 'rem_nome', 'valor', 'nat_op', 'tp_nf', 'situacao',
        'tp_evento', 'desc_evento', 'manifest_status', 'xml',
    ];

    private const LIST_COLUMNS = 'id, origem, settings_id, tipo, completo, propria, modelo, chave, numero, serie, dh_emi,
        emit_cnpj, emit_nome, dest_cnpj, dest_nome, rem_nome, valor, nat_op, tp_nf, situacao, tp_evento, desc_evento,
        manifest_status, manifest_msg, created_at';

    public const TIPOS_FILTRO = [
        'nfe' => "tipo IN ('nfe', 'nfe_resumo')",
        'cte' => "tipo = 'cte'",
        'evento' => "tipo = 'evento'",
    ];

    private ?bool $tablesReady = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Insere o documento; se já existir só substitui quando o novo é completo e o salvo era resumo.
     *
     * @param array<string, mixed> $doc
     * @return 'inserido'|'atualizado'|'existente'
     */
    public function upsert(array $doc): string
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare('SELECT id, completo FROM fiscal_docs WHERE dedupe_key = :k');
        $stmt->execute([':k' => (string) $doc['dedupe_key']]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ((int) $existing['completo'] === 1 || (int) ($doc['completo'] ?? 0) !== 1) {
                return 'existente';
            }
            $fields = array_intersect_key($doc, array_flip(self::COLUMNS));
            unset($fields['dedupe_key'], $fields['manifest_status'], $fields['settings_id']);
            $params = [':id' => (int) $existing['id']];
            foreach ($fields as $col => $value) {
                $params[':' . $col] = $value;
            }
            $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($fields)));
            $this->pdo->prepare('UPDATE fiscal_docs SET ' . $sets . ', updated_at = NOW() WHERE id = :id')->execute($params);

            return 'atualizado';
        }

        $params = [];
        foreach (self::COLUMNS as $col) {
            $params[':' . $col] = $doc[$col] ?? null;
        }
        $params[':completo'] = (int) ($doc['completo'] ?? 0);
        $params[':propria'] = (int) ($doc['propria'] ?? 0);
        $this->pdo->prepare(
            'INSERT INTO fiscal_docs (' . implode(', ', self::COLUMNS) . ', created_at, updated_at)
             VALUES (' . implode(', ', array_keys($params)) . ', NOW(), NOW())'
        )->execute($params);

        return 'inserido';
    }

    public function markCancelled(string $chave): void
    {
        $this->ensureTables();
        $this->pdo->prepare(
            "UPDATE fiscal_docs SET situacao = 'cancelada', updated_at = NOW()
             WHERE chave = :c AND tipo IN ('nfe', 'nfe_resumo', 'cte')"
        )->execute([':c' => $chave]);
    }

    /** @return list<array{id: int, chave: string}> resumos de NF-e da empresa que ainda não receberam a Ciência */
    public function pendingCiencia(int $settingsId, int $limit): array
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare(
            "SELECT id, chave FROM fiscal_docs
             WHERE settings_id = :s AND tipo = 'nfe_resumo' AND manifest_status = 'pendente' AND chave IS NOT NULL
             ORDER BY dh_emi ASC, id ASC LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute([':s' => $settingsId]);

        return $stmt->fetchAll();
    }

    public function setManifest(int $id, string $status, ?string $message): void
    {
        $this->ensureTables();
        $this->pdo->prepare(
            'UPDATE fiscal_docs SET manifest_status = :s, manifest_msg = :m, manifest_at = NOW(), updated_at = NOW() WHERE id = :id'
        )->execute([':s' => $status, ':m' => $message !== null ? mb_substr($message, 0, 255) : null, ':id' => $id]);
    }

    public function retryManifestErrors(): int
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare(
            "UPDATE fiscal_docs SET manifest_status = 'pendente', updated_at = NOW()
             WHERE tipo = 'nfe_resumo' AND manifest_status = 'erro'"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function countByOrigem(string $origem): int
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM fiscal_docs WHERE origem = :o');
        $stmt->execute([':o' => $origem]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> quantidade por situação da Ciência (pendente, ciencia, erro) */
    public function manifestCounts(): array
    {
        $this->ensureTables();
        $rows = $this->pdo->query(
            "SELECT manifest_status, COUNT(*) AS n FROM fiscal_docs
             WHERE tipo = 'nfe_resumo' AND manifest_status IS NOT NULL GROUP BY manifest_status"
        )->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['manifest_status']] = (int) $row['n'];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function listDocuments(array $filters, int $limit = 500): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::LIST_COLUMNS . ' FROM fiscal_docs' . $where
            . ' ORDER BY dh_emi DESC, id DESC LIMIT ' . max(1, min(5000, $limit))
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function countDocuments(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM fiscal_docs' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> */
    public function findIds(array $filters, int $limit = 5000): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM fiscal_docs' . $where . ' ORDER BY dh_emi DESC, id DESC LIMIT ' . max(1, min(5000, $limit))
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
            'SELECT id, tipo, completo, chave, tp_evento, dedupe_key, xml FROM fiscal_docs
             WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }

    /**
     * @param array{origem?: string, tipo?: string, propria?: string, situacao?: string, de?: string, ate?: string, busca?: string} $filters
     * @return array{0: string, 1: array<string, string|int>}
     */
    private function buildWhere(array $filters): array
    {
        $this->ensureTables();
        $conds = [];
        $params = [];

        $origem = (string) ($filters['origem'] ?? '');
        if (in_array($origem, ['sefaz_nfe', 'sefaz_cte', 'ml', 'rockit'], true)) {
            $conds[] = 'origem = :origem';
            $params[':origem'] = $origem;
        }
        $tipo = (string) ($filters['tipo'] ?? '');
        if (isset(self::TIPOS_FILTRO[$tipo])) {
            $conds[] = self::TIPOS_FILTRO[$tipo];
        }
        $propria = (string) ($filters['propria'] ?? '');
        if ($propria === '1' || $propria === '0') {
            $conds[] = 'propria = :propria';
            $params[':propria'] = (int) $propria;
        }
        $situacao = (string) ($filters['situacao'] ?? '');
        if ($situacao === 'cancelada') {
            $conds[] = "situacao = 'cancelada'";
        } elseif ($situacao === 'resumo') {
            $conds[] = 'completo = 0';
        } elseif ($situacao === 'completo') {
            $conds[] = 'completo = 1';
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

        $busca = trim((string) ($filters['busca'] ?? ''));
        if ($busca !== '') {
            $like = '%' . mb_strtolower($busca) . '%';
            $digits = preg_replace('/\D/', '', $busca) ?? '';
            $parts = [
                "LOWER(COALESCE(emit_nome, '')) LIKE :b1",
                "LOWER(COALESCE(dest_nome, '')) LIKE :b2",
                "LOWER(COALESCE(rem_nome, '')) LIKE :b3",
                "LOWER(COALESCE(nat_op, '')) LIKE :b4",
                "COALESCE(numero, '') = :b5",
            ];
            $params += [':b1' => $like, ':b2' => $like, ':b3' => $like, ':b4' => $like, ':b5' => ltrim($busca, '0') ?: $busca];
            if (strlen($digits) >= 6) {
                $parts[] = "COALESCE(chave, '') LIKE :b6";
                $parts[] = "COALESCE(emit_cnpj, '') LIKE :b7";
                $parts[] = "COALESCE(dest_cnpj, '') LIKE :b8";
                $params += [':b6' => '%' . $digits . '%', ':b7' => $digits . '%', ':b8' => $digits . '%'];
            }
            $conds[] = '(' . implode(' OR ', $parts) . ')';
        }

        return [$conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds), $params];
    }

    private function ensureTables(): void
    {
        if ($this->tablesReady === true) {
            return;
        }

        $pg = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $id = $pg ? 'id BIGSERIAL PRIMARY KEY' : 'id INT AUTO_INCREMENT PRIMARY KEY';
        $ts = $pg ? 'TIMESTAMP' : 'DATETIME';
        $bigText = $pg ? 'TEXT' : 'LONGTEXT';
        $money = $pg ? 'NUMERIC(15,2)' : 'DECIMAL(15,2)';
        $engine = $pg ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS fiscal_docs (
                {$id},
                dedupe_key VARCHAR(120) NOT NULL,
                origem VARCHAR(20) NOT NULL,
                settings_id BIGINT NULL,
                nsu VARCHAR(15) NULL,
                tipo VARCHAR(20) NOT NULL,
                completo SMALLINT NOT NULL DEFAULT 1,
                propria SMALLINT NOT NULL DEFAULT 0,
                modelo VARCHAR(5) NULL,
                chave VARCHAR(44) NULL,
                numero VARCHAR(20) NULL,
                serie VARCHAR(5) NULL,
                dh_emi {$ts} NULL,
                emit_cnpj VARCHAR(14) NULL,
                emit_nome VARCHAR(255) NULL,
                dest_cnpj VARCHAR(14) NULL,
                dest_nome VARCHAR(255) NULL,
                rem_nome VARCHAR(255) NULL,
                valor {$money} NULL,
                nat_op VARCHAR(120) NULL,
                tp_nf VARCHAR(1) NULL,
                situacao VARCHAR(20) NULL,
                tp_evento VARCHAR(10) NULL,
                desc_evento VARCHAR(120) NULL,
                manifest_status VARCHAR(20) NULL,
                manifest_msg VARCHAR(255) NULL,
                manifest_at {$ts} NULL,
                xml {$bigText} NOT NULL,
                created_at {$ts} NOT NULL,
                updated_at {$ts} NOT NULL
            ){$engine}"
        );

        $indexes = [
            'uq_fiscal_docs_dedupe' => 'CREATE UNIQUE INDEX uq_fiscal_docs_dedupe ON fiscal_docs (dedupe_key)',
            'idx_fiscal_docs_dh_emi' => 'CREATE INDEX idx_fiscal_docs_dh_emi ON fiscal_docs (dh_emi)',
            'idx_fiscal_docs_chave' => 'CREATE INDEX idx_fiscal_docs_chave ON fiscal_docs (chave)',
            'idx_fiscal_docs_manifest' => 'CREATE INDEX idx_fiscal_docs_manifest ON fiscal_docs (settings_id, manifest_status)',
        ];
        foreach ($indexes as $name => $sql) {
            if ($pg) {
                $stmt = $this->pdo->prepare('SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = :n');
            } else {
                $stmt = $this->pdo->prepare(
                    "SELECT 1 FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = 'fiscal_docs' AND index_name = :n LIMIT 1"
                );
            }
            $stmt->execute([':n' => $name]);
            if (!$stmt->fetchColumn()) {
                $this->pdo->exec($sql);
            }
        }

        $this->tablesReady = true;
    }
}
