<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class TaskRepository
{
    private bool $tableChecked = false;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array{
     *   subject: string,
     *   description: string,
     *   sector: string,
     *   requester_name: string,
     *   requester_email: string,
     *   status?: string,
     *   priority?: string
     * } $data
     */
    public function create(array $data): int
    {
        $this->ensureTablesExist();

        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_tasks
             (subject, description, sector, requester_name, requester_email, status, priority, created_at, updated_at)
             VALUES
             (:subject, :description, :sector, :requester_name, :requester_email, :status, :priority, NOW(), NOW())'
        );
        $stmt->execute([
            ':subject' => $data['subject'],
            ':description' => $data['description'],
            ':sector' => $data['sector'],
            ':requester_name' => $data['requester_name'],
            ':requester_email' => $data['requester_email'],
            ':status' => $data['status'] ?? 'aberta',
            ':priority' => $data['priority'] ?? 'normal',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $this->ensureTablesExist();

        $stmt = $this->pdo->prepare('SELECT * FROM portal_tasks WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 200): array
    {
        $this->ensureTablesExist();
        $limit = max(1, min(500, $limit));

        $sql = 'SELECT * FROM portal_tasks WHERE 1=1';
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'todos') {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $sector = trim((string) ($filters['sector'] ?? ''));
        if ($sector !== '' && $sector !== 'todos') {
            $sql .= ' AND sector = :sector';
            $params[':sector'] = $sector;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (subject LIKE :q OR description LIKE :q2 OR requester_name LIKE :q3 OR requester_email LIKE :q4)';
            $like = '%' . $q . '%';
            $params[':q'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
            $params[':q4'] = $like;
        }

        $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->ensureTablesExist();

        $stmt = $this->pdo->prepare(
            'UPDATE portal_tasks SET status = :status, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':id' => $id,
        ]);
    }

    /**
     * @param array{
     *   subject?: string,
     *   description?: string,
     *   sector?: string,
     *   requester_name?: string,
     *   requester_email?: string,
     *   priority?: string,
     *   status?: string
     * } $data
     */
    public function update(int $id, array $data): void
    {
        $this->ensureTablesExist();

        $fields = [];
        $params = [':id' => $id];
        foreach (['subject', 'description', 'sector', 'requester_name', 'requester_email', 'priority', 'status'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[":{$key}"] = $data[$key];
            }
        }

        if ($fields === []) {
            return;
        }

        $fields[] = 'updated_at = NOW()';
        $sql = 'UPDATE portal_tasks SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function touch(int $id): void
    {
        $this->ensureTablesExist();
        $stmt = $this->pdo->prepare('UPDATE portal_tasks SET updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @return array{id: int}
     */
    public function addEvent(
        int $taskId,
        string $eventType,
        string $message,
        ?string $actorName = null,
        bool $emailSent = false,
        ?string $emailError = null
    ): array {
        $this->ensureTablesExist();

        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_task_events
             (task_id, event_type, message, actor_name, email_sent, email_error, created_at)
             VALUES
             (:task_id, :event_type, :message, :actor_name, :email_sent, :email_error, NOW())'
        );
        $stmt->bindValue(':task_id', $taskId, PDO::PARAM_INT);
        $stmt->bindValue(':event_type', $eventType);
        $stmt->bindValue(':message', $message);
        $stmt->bindValue(':actor_name', $actorName);
        $stmt->bindValue(':email_sent', $emailSent, PDO::PARAM_BOOL);
        $stmt->bindValue(':email_error', $emailError);
        $stmt->execute();

        return ['id' => (int) $this->pdo->lastInsertId()];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEvents(int $taskId, int $limit = 100): array
    {
        $this->ensureTablesExist();
        $limit = max(1, min(200, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT * FROM portal_task_events WHERE task_id = :task_id ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([':task_id' => $taskId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return list<string>
     */
    public function listSectorsUsed(): array
    {
        $this->ensureTablesExist();
        $stmt = $this->pdo->query('SELECT DISTINCT sector FROM portal_tasks ORDER BY sector ASC');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];

        return array_values(array_filter(array_map('strval', $rows ?: [])));
    }

    private function ensureTablesExist(): void
    {
        if ($this->tableChecked) {
            return;
        }

        if ($this->isPgsql()) {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS portal_tasks (
                    id BIGSERIAL PRIMARY KEY,
                    subject VARCHAR(200) NOT NULL,
                    description TEXT NOT NULL,
                    sector VARCHAR(80) NOT NULL,
                    requester_name VARCHAR(120) NOT NULL,
                    requester_email VARCHAR(200) NOT NULL,
                    status VARCHAR(30) NOT NULL DEFAULT \'aberta\',
                    priority VARCHAR(20) NOT NULL DEFAULT \'normal\',
                    created_at TIMESTAMP NOT NULL,
                    updated_at TIMESTAMP NOT NULL
                )'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS portal_task_events (
                    id BIGSERIAL PRIMARY KEY,
                    task_id BIGINT NOT NULL,
                    event_type VARCHAR(40) NOT NULL,
                    message TEXT NOT NULL,
                    actor_name VARCHAR(120) DEFAULT NULL,
                    email_sent BOOLEAN NOT NULL DEFAULT FALSE,
                    email_error TEXT DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL
                )'
            );
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_portal_tasks_status ON portal_tasks (status)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_portal_tasks_updated ON portal_tasks (updated_at)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_portal_task_events_task ON portal_task_events (task_id)');
        } else {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS portal_tasks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    subject VARCHAR(200) NOT NULL,
                    description TEXT NOT NULL,
                    sector VARCHAR(80) NOT NULL,
                    requester_name VARCHAR(120) NOT NULL,
                    requester_email VARCHAR(200) NOT NULL,
                    status VARCHAR(30) NOT NULL DEFAULT \'aberta\',
                    priority VARCHAR(20) NOT NULL DEFAULT \'normal\',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    KEY idx_portal_tasks_status (status),
                    KEY idx_portal_tasks_updated (updated_at),
                    KEY idx_portal_tasks_sector (sector)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS portal_task_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    task_id INT NOT NULL,
                    event_type VARCHAR(40) NOT NULL,
                    message TEXT NOT NULL,
                    actor_name VARCHAR(120) DEFAULT NULL,
                    email_sent TINYINT(1) NOT NULL DEFAULT 0,
                    email_error TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    KEY idx_portal_task_events_task (task_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $this->tableChecked = true;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
