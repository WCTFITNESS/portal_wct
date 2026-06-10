<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\ProtheusAdHocQueryService;
use PDO;

class ProtheusSqlQueryHistoryRepository
{
    private const MAX_ENTRIES = 100;

    private ?bool $tableReady = null;

    private ?bool $hasOrderByColumn = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{
     *   table: string,
     *   columns: string,
     *   where: string,
     *   order_by?: string,
     *   top: int,
     *   sql: string,
     *   row_count: int,
     *   elapsed_ms: int
     * } $entry
     */
    public function save(array $entry): int
    {
        $this->ensureTable();

        $params = [
            ':table_name' => $entry['table'],
            ':columns_expr' => $entry['columns'],
            ':where_clause' => $entry['where'],
            ':order_by_clause' => (string) ($entry['order_by'] ?? ''),
            ':top_limit' => $entry['top'],
            ':sql_text' => $entry['sql'],
            ':row_count' => $entry['row_count'],
            ':elapsed_ms' => $entry['elapsed_ms'],
        ];

        if ($this->isPgsql()) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO protheus_sql_query_history
                    (table_name, columns_expr, where_clause, order_by_clause, top_limit, sql_text, row_count, elapsed_ms, created_at)
                 VALUES
                    (:table_name, :columns_expr, :where_clause, :order_by_clause, :top_limit, :sql_text, :row_count, :elapsed_ms, NOW())
                 RETURNING id'
            );
            $stmt->execute($params);
            $id = (int) $stmt->fetchColumn();
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO protheus_sql_query_history
                    (table_name, columns_expr, where_clause, order_by_clause, top_limit, sql_text, row_count, elapsed_ms, created_at)
                 VALUES
                    (:table_name, :columns_expr, :where_clause, :order_by_clause, :top_limit, :sql_text, :row_count, :elapsed_ms, NOW())'
            );
            $stmt->execute($params);
            $id = (int) $this->pdo->lastInsertId();
        }

        $this->pruneOld();

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 40): array
    {
        $this->ensureTable();
        $limit = max(1, min(100, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT id, table_name, columns_expr, where_clause, order_by_clause, top_limit, sql_text,
                    row_count, elapsed_ms, created_at
             FROM protheus_sql_query_history
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = $this->mapRow($row);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $this->ensureTable();
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, table_name, columns_expr, where_clause, order_by_clause, top_limit, sql_text,
                    row_count, elapsed_ms, created_at
             FROM protheus_sql_query_history
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function deleteById(int $id): bool
    {
        $this->ensureTable();
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM protheus_sql_query_history WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function pruneOld(): void
    {
        $limit = self::MAX_ENTRIES;
        $this->pdo->exec(
            'DELETE FROM protheus_sql_query_history
             WHERE id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM protheus_sql_query_history
                     ORDER BY id DESC
                     LIMIT ' . $limit . '
                 ) AS recent_ids
             )'
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $where = (string) ($row['where_clause'] ?? '');
        $whereShort = $where;
        if (strlen($whereShort) > 80) {
            $whereShort = substr($whereShort, 0, 77) . '…';
        }

        $tableName = (string) ($row['table_name'] ?? '');
        $columnsExpr = (string) ($row['columns_expr'] ?? '*');
        $isCount = $columnsExpr === ProtheusAdHocQueryService::COUNT_COLUMNS_MARKER;
        if ($tableName === ProtheusAdHocQueryService::RAW_QUERY_MARKER) {
            $tableName = 'Query pronta';
        } elseif ($isCount) {
            $tableName .= ' (COUNT)';
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'table' => $tableName,
            'is_raw' => ((string) ($row['table_name'] ?? '')) === ProtheusAdHocQueryService::RAW_QUERY_MARKER,
            'is_count' => $isCount,
            'columns' => $columnsExpr,
            'where' => $where,
            'where_short' => $whereShort,
            'order_by' => (string) ($row['order_by_clause'] ?? ''),
            'top' => (int) ($row['top_limit'] ?? 200),
            'sql' => (string) ($row['sql_text'] ?? ''),
            'row_count' => (int) ($row['row_count'] ?? 0),
            'elapsed_ms' => (int) ($row['elapsed_ms'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function ensureTable(): void
    {
        if ($this->tableReady === true) {
            return;
        }

        $sql = $this->isPgsql()
            ? 'CREATE TABLE IF NOT EXISTS protheus_sql_query_history (
                id BIGSERIAL PRIMARY KEY,
                table_name VARCHAR(64) NOT NULL,
                columns_expr VARCHAR(4000) NOT NULL DEFAULT \'*\',
                where_clause TEXT NOT NULL,
                order_by_clause TEXT NOT NULL DEFAULT \'\',
                top_limit INT NOT NULL DEFAULT 200,
                sql_text TEXT NOT NULL,
                row_count INT NOT NULL DEFAULT 0,
                elapsed_ms INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL
            )'
            : 'CREATE TABLE IF NOT EXISTS protheus_sql_query_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(64) NOT NULL,
                columns_expr VARCHAR(4000) NOT NULL DEFAULT \'*\',
                where_clause TEXT NOT NULL,
                order_by_clause TEXT NOT NULL,
                top_limit INT NOT NULL DEFAULT 200,
                sql_text TEXT NOT NULL,
                row_count INT NOT NULL DEFAULT 0,
                elapsed_ms INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                KEY idx_protheus_sql_hist_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->pdo->exec($sql);
        $this->tableReady = true;
        $this->ensureOrderByColumnExists();
    }

    private function ensureOrderByColumnExists(): void
    {
        if ($this->hasOrderByColumn()) {
            return;
        }

        if ($this->isPgsql()) {
            $this->pdo->exec(
                "ALTER TABLE protheus_sql_query_history ADD COLUMN order_by_clause TEXT NOT NULL DEFAULT ''"
            );
        } else {
            $this->pdo->exec(
                "ALTER TABLE protheus_sql_query_history ADD COLUMN order_by_clause TEXT NOT NULL DEFAULT '' AFTER where_clause"
            );
        }

        $this->hasOrderByColumn = true;
    }

    private function hasOrderByColumn(): bool
    {
        if ($this->hasOrderByColumn !== null) {
            return $this->hasOrderByColumn;
        }

        if ($this->isPgsql()) {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = 'protheus_sql_query_history'
                   AND column_name = 'order_by_clause'"
            );
        } else {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'protheus_sql_query_history'
                   AND column_name = 'order_by_clause'"
            );
        }

        $this->hasOrderByColumn = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasOrderByColumn;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
