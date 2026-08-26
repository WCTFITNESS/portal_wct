<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class FindCepSettingsRepository
{
    private ?bool $tableReady = null;

    /** Credenciais WCT (mesmo ambiente local/prod). */
    public const DEFAULTS = [
        'scheme' => 'https',
        'client_id' => 'wctfitness',
        'client_url_hash' => '7154b56482a054e5',
        'fid' => 'E3FWVW3L9KPAIQ',
        'referer' => 'E3FWVW3L9KPAIQ',
        'authorization' => '',
        'custom_base_url' => '',
        'timeout_seconds' => 30,
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function getSettings(): ?array
    {
        $this->ensureTable();
        $this->seedDefaultsIfEmpty();

        $stmt = $this->pdo->query('SELECT * FROM findcep_settings ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        // Compat: app usa a chave "authorization"; coluna no banco e api_authorization (PG reserved word).
        if (!isset($row['authorization']) && array_key_exists('api_authorization', $row)) {
            $row['authorization'] = $row['api_authorization'];
        }

        return $row;
    }

    /**
     * @param array{
     *   scheme?: string,
     *   client_id?: string,
     *   client_url_hash?: string,
     *   fid?: string,
     *   referer?: string,
     *   authorization?: string,
     *   custom_base_url?: string,
     *   timeout_seconds?: int|string
     * } $data
     */
    public function saveSettings(array $data): void
    {
        $this->ensureTable();

        $scheme = strtolower(trim((string) ($data['scheme'] ?? 'https')));
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $clientId = trim((string) ($data['client_id'] ?? ''));
        $clientUrlHash = trim((string) ($data['client_url_hash'] ?? ''));
        $fid = trim((string) ($data['fid'] ?? ''));
        $referer = trim((string) ($data['referer'] ?? ''));
        $authorization = trim((string) ($data['authorization'] ?? ''));
        $customBaseUrl = trim((string) ($data['custom_base_url'] ?? ''));
        $timeout = (int) ($data['timeout_seconds'] ?? 30);
        if ($timeout < 5) {
            $timeout = 5;
        }
        if ($timeout > 120) {
            $timeout = 120;
        }

        if ($clientId === '' && $customBaseUrl === '') {
            throw new \InvalidArgumentException('Informe o Client ID ou uma Base URL customizada.');
        }

        if ($referer === '' && $fid === '' && $clientId === '') {
            throw new \InvalidArgumentException('Informe Referer e/ou FID para identificação na API.');
        }

        $existing = $this->getSettings();
        if ($existing) {
            if ($authorization === '') {
                $authorization = (string) ($existing['authorization'] ?? $existing['api_authorization'] ?? '');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE findcep_settings SET
                    scheme = :scheme,
                    client_id = :client_id,
                    client_url_hash = :client_url_hash,
                    fid = :fid,
                    referer = :referer,
                    api_authorization = :api_authorization,
                    custom_base_url = :custom_base_url,
                    timeout_seconds = :timeout_seconds,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':scheme' => $scheme,
                ':client_id' => $clientId,
                ':client_url_hash' => $clientUrlHash,
                ':fid' => $fid,
                ':referer' => $referer,
                ':api_authorization' => $authorization,
                ':custom_base_url' => $customBaseUrl,
                ':timeout_seconds' => $timeout,
                ':id' => $existing['id'],
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO findcep_settings (
                scheme, client_id, client_url_hash, fid, referer, api_authorization, custom_base_url, timeout_seconds, created_at, updated_at
             ) VALUES (
                :scheme, :client_id, :client_url_hash, :fid, :referer, :api_authorization, :custom_base_url, :timeout_seconds, NOW(), NOW()
             )'
        );
        $stmt->execute([
            ':scheme' => $scheme,
            ':client_id' => $clientId,
            ':client_url_hash' => $clientUrlHash,
            ':fid' => $fid,
            ':referer' => $referer,
            ':api_authorization' => $authorization,
            ':custom_base_url' => $customBaseUrl,
            ':timeout_seconds' => $timeout,
        ]);
    }

    private function ensureTable(): void
    {
        if ($this->tableReady === true) {
            return;
        }

        $sql = $this->isPgsql()
            ? 'CREATE TABLE IF NOT EXISTS findcep_settings (
                id BIGSERIAL PRIMARY KEY,
                scheme VARCHAR(10) NOT NULL DEFAULT \'https\',
                client_id VARCHAR(120) NOT NULL DEFAULT \'\',
                client_url_hash VARCHAR(64) NOT NULL DEFAULT \'\',
                fid VARCHAR(120) NOT NULL DEFAULT \'\',
                referer TEXT NOT NULL DEFAULT \'\',
                api_authorization TEXT NOT NULL DEFAULT \'\',
                custom_base_url TEXT NOT NULL DEFAULT \'\',
                timeout_seconds INT NOT NULL DEFAULT 30,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL
            )'
            : 'CREATE TABLE IF NOT EXISTS findcep_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                scheme VARCHAR(10) NOT NULL DEFAULT \'https\',
                client_id VARCHAR(120) NOT NULL DEFAULT \'\',
                client_url_hash VARCHAR(64) NOT NULL DEFAULT \'\',
                fid VARCHAR(120) NOT NULL DEFAULT \'\',
                referer TEXT NOT NULL,
                api_authorization TEXT NOT NULL,
                custom_base_url TEXT NOT NULL,
                timeout_seconds INT NOT NULL DEFAULT 30,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->pdo->exec($sql);
        $this->migrateLegacyAuthorizationColumn();

        $this->tableReady = true;
    }

    private function seedDefaultsIfEmpty(): void
    {
        $stmt = $this->pdo->query('SELECT id FROM findcep_settings ORDER BY id ASC LIMIT 1');
        if ($stmt && $stmt->fetch()) {
            return;
        }

        $d = self::DEFAULTS;
        $ins = $this->pdo->prepare(
            'INSERT INTO findcep_settings (
                scheme, client_id, client_url_hash, fid, referer, api_authorization, custom_base_url, timeout_seconds, created_at, updated_at
             ) VALUES (
                :scheme, :client_id, :client_url_hash, :fid, :referer, :api_authorization, :custom_base_url, :timeout_seconds, NOW(), NOW()
             )'
        );
        $ins->execute([
            ':scheme' => $d['scheme'],
            ':client_id' => $d['client_id'],
            ':client_url_hash' => $d['client_url_hash'],
            ':fid' => $d['fid'],
            ':referer' => $d['referer'],
            ':api_authorization' => $d['authorization'],
            ':custom_base_url' => $d['custom_base_url'],
            ':timeout_seconds' => $d['timeout_seconds'],
        ]);
    }

    /** Ambientes MySQL locais que criaram a coluna antiga "authorization". */
    private function migrateLegacyAuthorizationColumn(): void
    {
        if ($this->hasColumn('api_authorization')) {
            return;
        }

        if ($this->isPgsql()) {
            if ($this->hasColumn('authorization')) {
                $this->pdo->exec('ALTER TABLE findcep_settings RENAME COLUMN authorization TO api_authorization');
            } else {
                $this->pdo->exec(
                    'ALTER TABLE findcep_settings ADD COLUMN api_authorization TEXT NOT NULL DEFAULT \'\''
                );
            }

            return;
        }

        if ($this->hasColumn('authorization')) {
            $this->pdo->exec(
                'ALTER TABLE findcep_settings CHANGE COLUMN authorization api_authorization TEXT NOT NULL'
            );
        } else {
            $this->pdo->exec(
                'ALTER TABLE findcep_settings ADD COLUMN api_authorization TEXT NOT NULL'
            );
        }
    }

    private function hasColumn(string $column): bool
    {
        if ($this->isPgsql()) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = :table
                   AND column_name = :column
                 LIMIT 1'
            );
            $stmt->execute([':table' => 'findcep_settings', ':column' => $column]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :column
             LIMIT 1'
        );
        $stmt->execute([':table' => 'findcep_settings', ':column' => $column]);

        return (bool) $stmt->fetchColumn();
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
