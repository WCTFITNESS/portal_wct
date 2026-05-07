<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SettingsRepository
{
    private ?bool $hasOauthCodeColumn = null;
    private ?bool $hasLexosTokenColumn = null;
    private ?bool $hasLexosCodeColumn = null;
    private ?bool $hasLexosRefreshTokenColumn = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function getApiConfig(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM api_settings LIMIT 1');
        $data = $stmt->fetch();

        return $data ?: null;
    }

    public function saveApiConfig(array $data): void
    {
        $this->ensureOauthCodeColumnExists();
        $this->ensureLexosCodeColumnExists();
        $this->ensureLexosTokenColumnExists();
        $this->ensureLexosRefreshTokenColumnExists();
        $existing = $this->getApiConfig();

        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE api_settings
                 SET app_id = :app_id,
                     client_secret = :client_secret,
                     redirect_uri = :redirect_uri,
                     seller_id = :seller_id,
                     oauth_code = :oauth_code,
                     lexos_code = :lexos_code,
                     lexos_token = :lexos_token,
                     lexos_refresh_token = :lexos_refresh_token,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':app_id' => (string) ($data['app_id'] ?? ''),
                ':client_secret' => (string) ($data['client_secret'] ?? ''),
                ':redirect_uri' => (string) ($data['redirect_uri'] ?? ''),
                ':seller_id' => (string) ($data['seller_id'] ?? ''),
                ':oauth_code' => (($data['oauth_code'] ?? '') !== '') ? (string) $data['oauth_code'] : null,
                ':lexos_code' => (($data['lexos_code'] ?? '') !== '') ? (string) $data['lexos_code'] : null,
                ':lexos_token' => (($data['lexos_token'] ?? '') !== '') ? (string) $data['lexos_token'] : null,
                ':lexos_refresh_token' => (($data['lexos_refresh_token'] ?? '') !== '') ? (string) $data['lexos_refresh_token'] : null,
                ':id' => $existing['id'],
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO api_settings (
                app_id, client_secret, redirect_uri, seller_id, oauth_code, lexos_code, lexos_token, lexos_refresh_token, created_at, updated_at
             ) VALUES (
                :app_id, :client_secret, :redirect_uri, :seller_id, :oauth_code, :lexos_code, :lexos_token, :lexos_refresh_token, NOW(), NOW()
             )'
        );
        $stmt->execute([
            ':app_id' => (string) ($data['app_id'] ?? ''),
            ':client_secret' => (string) ($data['client_secret'] ?? ''),
            ':redirect_uri' => (string) ($data['redirect_uri'] ?? ''),
            ':seller_id' => (string) ($data['seller_id'] ?? ''),
            ':oauth_code' => (($data['oauth_code'] ?? '') !== '') ? (string) $data['oauth_code'] : null,
            ':lexos_code' => (($data['lexos_code'] ?? '') !== '') ? (string) $data['lexos_code'] : null,
            ':lexos_token' => (($data['lexos_token'] ?? '') !== '') ? (string) $data['lexos_token'] : null,
            ':lexos_refresh_token' => (($data['lexos_refresh_token'] ?? '') !== '') ? (string) $data['lexos_refresh_token'] : null,
        ]);
    }

    private function ensureOauthCodeColumnExists(): void
    {
        if ($this->hasOauthCodeColumn()) {
            return;
        }

        if ($this->isPgsql()) {
            $this->pdo->exec('ALTER TABLE api_settings ADD COLUMN oauth_code VARCHAR(255) DEFAULT NULL');
        } else {
            $this->pdo->exec('ALTER TABLE api_settings ADD COLUMN oauth_code VARCHAR(255) DEFAULT NULL AFTER seller_id');
        }
        $this->hasOauthCodeColumn = true;
    }

    private function hasOauthCodeColumn(): bool
    {
        if ($this->hasOauthCodeColumn !== null) {
            return $this->hasOauthCodeColumn;
        }

        if ($this->isPgsql()) {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = 'api_settings'
                   AND column_name = 'oauth_code'"
            );
        } else {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'api_settings'
                   AND column_name = 'oauth_code'"
            );
        }

        $this->hasOauthCodeColumn = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasOauthCodeColumn;
    }

    private function ensureLexosTokenColumnExists(): void
    {
        if ($this->hasLexosTokenColumn()) {
            return;
        }

        if ($this->isPgsql()) {
            $this->pdo->exec('ALTER TABLE api_settings ADD COLUMN lexos_token TEXT DEFAULT NULL');
        } else {
            $this->pdo->exec('ALTER TABLE api_settings ADD COLUMN lexos_token TEXT NULL AFTER oauth_code');
        }
        $this->hasLexosTokenColumn = true;
    }

    private function ensureLexosCodeColumnExists(): void
    {
        if ($this->hasLexosCodeColumn()) {
            return;
        }

        if ($this->isPgsql()) {
            $this->pdo->exec('ALTER TABLE api_settings ADD COLUMN lexos_code TEXT DEFAULT NULL');
        } else {
            $this->pdo->exec('ALTER TABLE api_settings ADD COLUMN lexos_code TEXT NULL AFTER oauth_code');
        }
        $this->hasLexosCodeColumn = true;
    }

    private function hasLexosCodeColumn(): bool
    {
        if ($this->hasLexosCodeColumn !== null) {
            return $this->hasLexosCodeColumn;
        }

        if ($this->isPgsql()) {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = 'api_settings'
                   AND column_name = 'lexos_code'"
            );
        } else {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'api_settings'
                   AND column_name = 'lexos_code'"
            );
        }

        $this->hasLexosCodeColumn = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasLexosCodeColumn;
    }

    private function ensureLexosRefreshTokenColumnExists(): void
    {
        if ($this->hasLexosRefreshTokenColumn()) {
            return;
        }

        if ($this->isPgsql()) {
            $this->pdo->exec('ALTER TABLE api_settings ADD COLUMN lexos_refresh_token TEXT DEFAULT NULL');
        } else {
            $this->pdo->exec('ALTER TABLE api_settings ADD COLUMN lexos_refresh_token TEXT NULL AFTER lexos_token');
        }
        $this->hasLexosRefreshTokenColumn = true;
    }

    private function hasLexosRefreshTokenColumn(): bool
    {
        if ($this->hasLexosRefreshTokenColumn !== null) {
            return $this->hasLexosRefreshTokenColumn;
        }

        if ($this->isPgsql()) {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = 'api_settings'
                   AND column_name = 'lexos_refresh_token'"
            );
        } else {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'api_settings'
                   AND column_name = 'lexos_refresh_token'"
            );
        }

        $this->hasLexosRefreshTokenColumn = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasLexosRefreshTokenColumn;
    }

    private function hasLexosTokenColumn(): bool
    {
        if ($this->hasLexosTokenColumn !== null) {
            return $this->hasLexosTokenColumn;
        }

        if ($this->isPgsql()) {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = 'api_settings'
                   AND column_name = 'lexos_token'"
            );
        } else {
            $stmt = $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'api_settings'
                   AND column_name = 'lexos_token'"
            );
        }

        $this->hasLexosTokenColumn = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasLexosTokenColumn;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
