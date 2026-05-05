<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class TokenRepository
{
    private ?bool $scopeColumnIsWide = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function getLatestToken(): ?array
    {
        $stmt = $this->pdo->query('SELECT * FROM oauth_tokens ORDER BY id DESC LIMIT 1');
        $token = $stmt->fetch();

        return $token ?: null;
    }

    public function saveToken(array $data): void
    {
        $this->ensureScopeColumnIsWide();
        $existing = $this->getLatestToken();

        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE oauth_tokens
                 SET access_token = :access_token,
                     refresh_token = :refresh_token,
                     expires_in = :expires_in,
                     expires_at = :expires_at,
                     token_type = :token_type,
                     scope = :scope,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':access_token' => $data['access_token'],
                ':refresh_token' => $data['refresh_token'],
                ':expires_in' => $data['expires_in'],
                ':expires_at' => $data['expires_at'],
                ':token_type' => $data['token_type'] ?? 'Bearer',
                ':scope' => $data['scope'] ?? null,
                ':id' => $existing['id'],
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO oauth_tokens
             (access_token, refresh_token, expires_in, expires_at, token_type, scope, created_at, updated_at)
             VALUES (:access_token, :refresh_token, :expires_in, :expires_at, :token_type, :scope, NOW(), NOW())'
        );
        $stmt->execute([
            ':access_token' => $data['access_token'],
            ':refresh_token' => $data['refresh_token'],
            ':expires_in' => $data['expires_in'],
            ':expires_at' => $data['expires_at'],
            ':token_type' => $data['token_type'] ?? 'Bearer',
            ':scope' => $data['scope'] ?? null,
        ]);
    }

    private function ensureScopeColumnIsWide(): void
    {
        if ($this->scopeColumnIsWide === true) {
            return;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $this->pdo->query(
                "SELECT data_type
                 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = 'oauth_tokens'
                   AND column_name = 'scope'
                 LIMIT 1"
            );
            $dataType = strtolower((string) $stmt->fetchColumn());
            if ($dataType !== '' && $dataType !== 'text') {
                $this->pdo->exec('ALTER TABLE oauth_tokens ALTER COLUMN scope TYPE TEXT');
            }
        } else {
            $stmt = $this->pdo->query(
                "SELECT data_type
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'oauth_tokens'
                   AND column_name = 'scope'
                 LIMIT 1"
            );
            $dataType = strtolower((string) $stmt->fetchColumn());
            if ($dataType !== '' && $dataType !== 'text') {
                $this->pdo->exec('ALTER TABLE oauth_tokens MODIFY scope TEXT NULL');
            }
        }

        $this->scopeColumnIsWide = true;
    }
}
