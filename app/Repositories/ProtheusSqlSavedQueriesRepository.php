<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ProtheusSqlSavedQueriesRepository
{
    private const MAX_ENTRIES = 80;

    private ?bool $tableReady = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array{id: int, title: string, sql_short: string, created_at: string, updated_at: string}>
     */
    public function listAll(): array
    {
        $this->ensureTable();

        $stmt = $this->pdo->query(
            'SELECT id, title, sql_text, created_at, updated_at
             FROM protheus_sql_saved_queries
             ORDER BY updated_at DESC, id DESC
             LIMIT ' . self::MAX_ENTRIES
        );

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sql = (string) ($row['sql_text'] ?? '');
            $short = $sql;
            if (strlen($short) > 100) {
                $short = substr($short, 0, 97) . '…';
            }
            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'title' => (string) ($row['title'] ?? ''),
                'sql_short' => $short,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @return array{id: int, title: string, sql: string, created_at: string, updated_at: string}|null
     */
    public function findById(int $id): ?array
    {
        $this->ensureTable();
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, title, sql_text, created_at, updated_at
             FROM protheus_sql_saved_queries
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => (string) ($row['title'] ?? ''),
            'sql' => (string) ($row['sql_text'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public function save(string $title, string $sql): int
    {
        $this->ensureTable();
        $title = trim($title);
        if ($title === '') {
            $title = 'Query ' . date('d/m/Y H:i');
        }
        if (strlen($title) > 120) {
            $title = substr($title, 0, 120);
        }

        if ($this->isPgsql()) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO protheus_sql_saved_queries (title, sql_text, created_at, updated_at)
                 VALUES (:title, :sql, NOW(), NOW())
                 RETURNING id'
            );
            $stmt->execute([':title' => $title, ':sql' => $sql]);
            $id = (int) $stmt->fetchColumn();
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO protheus_sql_saved_queries (title, sql_text, created_at, updated_at)
                 VALUES (:title, :sql, NOW(), NOW())'
            );
            $stmt->execute([':title' => $title, ':sql' => $sql]);
            $id = (int) $this->pdo->lastInsertId();
        }

        $this->pruneOld();

        return $id;
    }

    public function deleteById(int $id): bool
    {
        $this->ensureTable();
        if ($id <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM protheus_sql_saved_queries WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    private function pruneOld(): void
    {
        $limit = self::MAX_ENTRIES;
        $this->pdo->exec(
            'DELETE FROM protheus_sql_saved_queries
             WHERE id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM protheus_sql_saved_queries
                     ORDER BY id DESC
                     LIMIT ' . $limit . '
                 ) AS recent_ids
             )'
        );
    }

    private function ensureTable(): void
    {
        if ($this->tableReady === true) {
            return;
        }

        $sql = $this->isPgsql()
            ? 'CREATE TABLE IF NOT EXISTS protheus_sql_saved_queries (
                id BIGSERIAL PRIMARY KEY,
                title VARCHAR(120) NOT NULL,
                sql_text TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )'
            : 'CREATE TABLE IF NOT EXISTS protheus_sql_saved_queries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(120) NOT NULL,
                sql_text MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_sql_saved_updated (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->pdo->exec($sql);
        $this->tableReady = true;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
