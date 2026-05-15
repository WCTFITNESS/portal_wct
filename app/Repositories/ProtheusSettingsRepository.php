<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ProtheusSettingsRepository
{
    private ?bool $tableReady = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function getSettings(): ?array
    {
        $this->ensureTable();

        $stmt = $this->pdo->query('SELECT * FROM protheus_settings ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @param array{host: string, database_name: string, port: string|int, username: string, password?: string} $data
     */
    public function saveSettings(array $data): void
    {
        $this->ensureTable();

        $host = trim((string) ($data['host'] ?? ''));
        $database = trim((string) ($data['database_name'] ?? ''));
        $port = trim((string) ($data['port'] ?? '1433'));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($host === '' || $database === '' || $username === '') {
            throw new \InvalidArgumentException('Informe host, banco e usuario do Protheus.');
        }

        if ($port === '' || !ctype_digit($port)) {
            $port = '1433';
        }

        $existing = $this->getSettings();
        if ($existing) {
            if ($password === '') {
                $password = (string) ($existing['password'] ?? '');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE protheus_settings SET
                    host = :host,
                    database_name = :database_name,
                    port = :port,
                    username = :username,
                    password = :password,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':host' => $host,
                ':database_name' => $database,
                ':port' => (int) $port,
                ':username' => $username,
                ':password' => $password,
                ':id' => $existing['id'],
            ]);

            return;
        }

        if ($password === '') {
            throw new \InvalidArgumentException('Informe a senha na primeira configuracao.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO protheus_settings (host, database_name, port, username, password, created_at, updated_at)
             VALUES (:host, :database_name, :port, :username, :password, NOW(), NOW())'
        );
        $stmt->execute([
            ':host' => $host,
            ':database_name' => $database,
            ':port' => (int) $port,
            ':username' => $username,
            ':password' => $password,
        ]);
    }

    private function ensureTable(): void
    {
        if ($this->tableReady === true) {
            return;
        }

        $sql = $this->isPgsql()
            ? 'CREATE TABLE IF NOT EXISTS protheus_settings (
                id BIGSERIAL PRIMARY KEY,
                host VARCHAR(255) NOT NULL,
                database_name VARCHAR(120) NOT NULL,
                port INT NOT NULL DEFAULT 1433,
                username VARCHAR(120) NOT NULL,
                password TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )'
            : 'CREATE TABLE IF NOT EXISTS protheus_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                host VARCHAR(255) NOT NULL,
                database_name VARCHAR(120) NOT NULL,
                port INT NOT NULL DEFAULT 1433,
                username VARCHAR(120) NOT NULL,
                password TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->pdo->exec($sql);

        $this->tableReady = true;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
