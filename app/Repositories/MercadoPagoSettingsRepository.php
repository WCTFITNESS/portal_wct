<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class MercadoPagoSettingsRepository
{
    private ?bool $tableReady = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function getSettings(): ?array
    {
        $this->ensureTable();

        $stmt = $this->pdo->query('SELECT * FROM mercadopago_settings ORDER BY id ASC LIMIT 1');

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getAccessToken(): string
    {
        $row = $this->getSettings();

        return trim((string) ($row['access_token'] ?? ''));
    }

    public function saveAccessToken(string $accessToken): void
    {
        $this->ensureTable();
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            throw new \InvalidArgumentException('Informe o access token do Mercado Pago.');
        }

        $existing = $this->getSettings();
        if ($existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE mercadopago_settings SET access_token = :token, updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([':token' => $accessToken, ':id' => $existing['id']]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mercadopago_settings (access_token, created_at, updated_at) VALUES (:token, NOW(), NOW())'
        );
        $stmt->execute([':token' => $accessToken]);
    }

    private function ensureTable(): void
    {
        if ($this->tableReady === true) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS mercadopago_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                access_token TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->tableReady = true;
    }
}
