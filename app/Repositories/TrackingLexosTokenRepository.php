<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\TrackingDatabase;
use PDO;
use Throwable;

final class TrackingLexosTokenRepository
{
    public function __construct(private TrackingDatabase $trackingDatabase)
    {
    }

    public function isAvailable(): bool
    {
        return $this->trackingDatabase->isConfigured();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatest(): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $stmt = $this->trackingDatabase->pdo()->query(
            'SELECT id, access_token, token_type, expires_in, refresh_token,
                    refresh_token_expires_in, chave, atualizado_em, refresh_token_updated_at
             FROM lexos_tokens
             ORDER BY id DESC
             LIMIT 1'
        );

        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * @return array{ok: bool, error: string, row: array<string, mixed>|null}
     */
    public function testConnection(): array
    {
        if (!$this->isAvailable()) {
            return ['ok' => false, 'error' => 'URL não configurada', 'row' => null];
        }

        try {
            $row = $this->findLatest();

            return ['ok' => true, 'error' => '', 'row' => $row];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'row' => null];
        }
    }

    public function updateTokens(
        string $accessToken,
        int $expiresIn,
        string $refreshToken,
        ?int $refreshTokenExpiresIn = null
    ): void {
        if (!$this->isAvailable()) {
            return;
        }

        $existing = $this->findLatest();
        if ($existing === null) {
            return;
        }

        $stmt = $this->trackingDatabase->pdo()->prepare(
            'UPDATE lexos_tokens SET
                access_token = :access_token,
                expires_in = :expires_in,
                refresh_token = :refresh_token,
                refresh_token_expires_in = COALESCE(:refresh_expires, refresh_token_expires_in),
                atualizado_em = NOW(),
                refresh_token_updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':access_token' => $accessToken,
            ':expires_in' => $expiresIn,
            ':refresh_token' => $refreshToken,
            ':refresh_expires' => $refreshTokenExpiresIn,
            ':id' => $existing['id'],
        ]);
    }

    /**
     * Status sem expor segredos completos.
     *
     * @return array<string, mixed>
     */
    public function getPublicStatus(?array $row = null): array
    {
        $row ??= $this->findLatest();
        if ($row === null) {
            return [
                'connected' => $this->isAvailable(),
                'has_row' => false,
                'has_token' => false,
                'has_refresh' => false,
                'has_chave' => false,
                'atualizado_em' => null,
                'token_preview' => '',
                'chave_preview' => '',
            ];
        }

        $token = trim((string) ($row['access_token'] ?? ''));
        $chave = trim((string) ($row['chave'] ?? ''));

        return [
            'connected' => true,
            'has_row' => true,
            'has_token' => strlen($token) > 20,
            'has_refresh' => strlen(trim((string) ($row['refresh_token'] ?? ''))) > 10,
            'has_chave' => $chave !== '',
            'atualizado_em' => (string) ($row['atualizado_em'] ?? ''),
            'token_preview' => self::maskSecret($token),
            'chave_preview' => self::maskSecret($chave),
        ];
    }

    private static function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '—';
        }
        if (strlen($value) <= 8) {
            return '****';
        }

        return substr($value, 0, 4) . '…' . substr($value, -4);
    }
}
