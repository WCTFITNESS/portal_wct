<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class PortalUserRepository
{
    private ?bool $tableReady = null;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUsers(): array
    {
        $this->ensureTable();
        $stmt = $this->pdo->query(
            'SELECT id, name, email, is_admin, is_active, modules_json, created_at, updated_at
             FROM portal_users
             ORDER BY name ASC, id ASC'
        );

        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function findById(int $id): ?array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, is_admin, is_active, modules_json,
                    reset_token_hash, reset_expires_at, created_at, updated_at
             FROM portal_users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?array
    {
        $this->ensureTable();
        $email = strtolower(trim($email));
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, is_admin, is_active, modules_json,
                    reset_token_hash, reset_expires_at, created_at, updated_at
             FROM portal_users WHERE LOWER(email) = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function findByResetTokenHash(string $tokenHash): ?array
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare(
            'SELECT id, name, email, password_hash, is_admin, is_active, modules_json,
                    reset_token_hash, reset_expires_at, created_at, updated_at
             FROM portal_users
             WHERE reset_token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @param list<string> $modules
     */
    public function create(
        string $name,
        string $email,
        string $passwordHash,
        bool $isAdmin,
        bool $isActive,
        array $modules
    ): int {
        $this->ensureTable();
        if ($this->isPgsql()) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO portal_users (
                    name, email, password_hash, is_admin, is_active, modules_json, created_at, updated_at
                 ) VALUES (
                    :name, :email, :password_hash, :is_admin, :is_active, :modules_json, NOW(), NOW()
                 ) RETURNING id'
            );
            $stmt->execute([
                ':name' => trim($name),
                ':email' => strtolower(trim($email)),
                ':password_hash' => $passwordHash,
                ':is_admin' => $isAdmin ? 1 : 0,
                ':is_active' => $isActive ? 1 : 0,
                ':modules_json' => json_encode(array_values($modules), JSON_UNESCAPED_UNICODE) ?: '[]',
            ]);

            return (int) $stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_users (
                name, email, password_hash, is_admin, is_active, modules_json, created_at, updated_at
             ) VALUES (
                :name, :email, :password_hash, :is_admin, :is_active, :modules_json, NOW(), NOW()
             )'
        );
        $stmt->execute([
            ':name' => trim($name),
            ':email' => strtolower(trim($email)),
            ':password_hash' => $passwordHash,
            ':is_admin' => $isAdmin ? 1 : 0,
            ':is_active' => $isActive ? 1 : 0,
            ':modules_json' => json_encode(array_values($modules), JSON_UNESCAPED_UNICODE) ?: '[]',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<string> $modules
     */
    public function update(
        int $id,
        string $name,
        string $email,
        bool $isAdmin,
        bool $isActive,
        array $modules,
        ?string $passwordHash = null
    ): void {
        $this->ensureTable();
        if ($passwordHash !== null && $passwordHash !== '') {
            $stmt = $this->pdo->prepare(
                'UPDATE portal_users SET
                    name = :name,
                    email = :email,
                    password_hash = :password_hash,
                    is_admin = :is_admin,
                    is_active = :is_active,
                    modules_json = :modules_json,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':name' => trim($name),
                ':email' => strtolower(trim($email)),
                ':password_hash' => $passwordHash,
                ':is_admin' => $isAdmin ? 1 : 0,
                ':is_active' => $isActive ? 1 : 0,
                ':modules_json' => json_encode(array_values($modules), JSON_UNESCAPED_UNICODE) ?: '[]',
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE portal_users SET
                name = :name,
                email = :email,
                is_admin = :is_admin,
                is_active = :is_active,
                modules_json = :modules_json,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':name' => trim($name),
            ':email' => strtolower(trim($email)),
            ':is_admin' => $isAdmin ? 1 : 0,
            ':is_active' => $isActive ? 1 : 0,
            ':modules_json' => json_encode(array_values($modules), JSON_UNESCAPED_UNICODE) ?: '[]',
        ]);
    }

    public function delete(int $id): void
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare('DELETE FROM portal_users WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function setResetToken(int $id, string $tokenHash, string $expiresAt): void
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare(
            'UPDATE portal_users SET
                reset_token_hash = :hash,
                reset_expires_at = :expires,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':hash' => $tokenHash,
            ':expires' => $expiresAt,
        ]);
    }

    public function clearResetToken(int $id): void
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare(
            'UPDATE portal_users SET
                reset_token_hash = NULL,
                reset_expires_at = NULL,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->ensureTable();
        $stmt = $this->pdo->prepare(
            'UPDATE portal_users SET
                password_hash = :password_hash,
                reset_token_hash = NULL,
                reset_expires_at = NULL,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':password_hash' => $passwordHash,
        ]);
    }

    public function countUsers(): int
    {
        $this->ensureTable();
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM portal_users');

        return (int) ($stmt ? $stmt->fetchColumn() : 0);
    }

    public function ensureTable(): void
    {
        if ($this->tableReady === true) {
            return;
        }

        $sql = $this->isPgsql()
            ? 'CREATE TABLE IF NOT EXISTS portal_users (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(200) NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                is_admin BOOLEAN NOT NULL DEFAULT FALSE,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                modules_json TEXT NOT NULL DEFAULT \'[]\',
                reset_token_hash TEXT DEFAULT NULL,
                reset_expires_at TIMESTAMP DEFAULT NULL,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )'
            : 'CREATE TABLE IF NOT EXISTS portal_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(200) NOT NULL,
                password_hash TEXT NOT NULL,
                is_admin TINYINT(1) NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                modules_json TEXT NOT NULL,
                reset_token_hash VARCHAR(128) DEFAULT NULL,
                reset_expires_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_portal_users_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->pdo->exec($sql);
        $this->tableReady = true;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $modules = [];
        $raw = trim((string) ($row['modules_json'] ?? ''));
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    $key = trim((string) $item);
                    if ($key !== '') {
                        $modules[] = $key;
                    }
                }
            }
        }

        $row['modules'] = array_values(array_unique($modules));
        $row['is_admin'] = !empty($row['is_admin']);
        $row['is_active'] = !empty($row['is_active']);

        return $row;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
