<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class FindCepSettingsRepository
{
    private ?bool $tableReady = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function getSettings(): ?array
    {
        $this->ensureTable();

        $stmt = $this->pdo->query('SELECT * FROM findcep_settings ORDER BY id ASC LIMIT 1');
        $row = $stmt->fetch();

        return $row ?: null;
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
                $authorization = (string) ($existing['authorization'] ?? '');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE findcep_settings SET
                    scheme = :scheme,
                    client_id = :client_id,
                    client_url_hash = :client_url_hash,
                    fid = :fid,
                    referer = :referer,
                    authorization = :authorization,
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
                ':authorization' => $authorization,
                ':custom_base_url' => $customBaseUrl,
                ':timeout_seconds' => $timeout,
                ':id' => $existing['id'],
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO findcep_settings (
                scheme, client_id, client_url_hash, fid, referer, authorization, custom_base_url, timeout_seconds, created_at, updated_at
             ) VALUES (
                :scheme, :client_id, :client_url_hash, :fid, :referer, :authorization, :custom_base_url, :timeout_seconds, NOW(), NOW()
             )'
        );
        $stmt->execute([
            ':scheme' => $scheme,
            ':client_id' => $clientId,
            ':client_url_hash' => $clientUrlHash,
            ':fid' => $fid,
            ':referer' => $referer,
            ':authorization' => $authorization,
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
                authorization TEXT NOT NULL DEFAULT \'\',
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
                authorization TEXT NOT NULL,
                custom_base_url TEXT NOT NULL,
                timeout_seconds INT NOT NULL DEFAULT 30,
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
