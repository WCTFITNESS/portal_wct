<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ProtheusSettingsRepository
{
    public const DEFAULT_DATA_CORTE = '2026-04-01';

    private ?bool $tableReady = null;
    private ?bool $hasDataCorteColumn = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function getSettings(): ?array
    {
        $this->ensureTable();
        $this->ensureDataCorteColumnExists();

        $stmt = $this->pdo->query('SELECT * FROM protheus_settings ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function resolveDataCorte(?array $settings): string
    {
        $raw = trim((string) ($settings['data_corte'] ?? ''));

        return $raw !== '' ? self::normalizeDataCorte($raw) : self::DEFAULT_DATA_CORTE;
    }

    public static function normalizeDataCorte(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }

        return self::DEFAULT_DATA_CORTE;
    }

    /**
     * @param array{host: string, database_name: string, port: string|int, username: string, password?: string, data_corte?: string} $data
     */
    public function saveSettings(array $data): void
    {
        $this->ensureTable();
        $this->ensureDataCorteColumnExists();

        $host = trim((string) ($data['host'] ?? ''));
        $database = trim((string) ($data['database_name'] ?? ''));
        $port = trim((string) ($data['port'] ?? '1433'));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $dataCorte = self::normalizeDataCorte((string) ($data['data_corte'] ?? self::DEFAULT_DATA_CORTE));

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
                    data_corte = :data_corte,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':host' => $host,
                ':database_name' => $database,
                ':port' => (int) $port,
                ':username' => $username,
                ':password' => $password,
                ':data_corte' => $dataCorte,
                ':id' => $existing['id'],
            ]);

            return;
        }

        if ($password === '') {
            throw new \InvalidArgumentException('Informe a senha na primeira configuracao.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO protheus_settings (host, database_name, port, username, password, data_corte, created_at, updated_at)
             VALUES (:host, :database_name, :port, :username, :password, :data_corte, NOW(), NOW())'
        );
        $stmt->execute([
            ':host' => $host,
            ':database_name' => $database,
            ':port' => (int) $port,
            ':username' => $username,
            ':password' => $password,
            ':data_corte' => $dataCorte,
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
                data_corte DATE NOT NULL DEFAULT \'2026-04-01\',
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
                data_corte DATE NOT NULL DEFAULT \'2026-04-01\',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->pdo->exec($sql);

        $this->tableReady = true;
        $this->ensureDataCorteColumnExists();
    }

    private function ensureDataCorteColumnExists(): void
    {
        if ($this->hasDataCorteColumn()) {
            return;
        }

        if ($this->isPgsql()) {
            $this->pdo->exec(
                'ALTER TABLE protheus_settings ADD COLUMN data_corte DATE NOT NULL DEFAULT \'2026-04-01\''
            );
        } else {
            $this->pdo->exec(
                'ALTER TABLE protheus_settings ADD COLUMN data_corte DATE NOT NULL DEFAULT \'2026-04-01\' AFTER password'
            );
        }

        $this->hasDataCorteColumn = true;
    }

    private function hasDataCorteColumn(): bool
    {
        if ($this->hasDataCorteColumn !== null) {
            return $this->hasDataCorteColumn;
        }

        if ($this->isPgsql()) {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = 'protheus_settings'
                   AND column_name = 'data_corte'"
            );
        } else {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'protheus_settings'
                   AND column_name = 'data_corte'"
            );
        }

        $this->hasDataCorteColumn = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasDataCorteColumn;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
