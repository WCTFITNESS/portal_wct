<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use App\Repositories\TrackingLexosTokenRepository;
use RuntimeException;

/**
 * Envia credenciais Lexos do Portal WCT para o Tracking (DB direto ou webhook).
 */
final class TrackingLexosSyncService
{
    public function __construct(
        private SettingsRepository $settingsRepository,
        private TrackingLexosTokenRepository $trackingLexosTokenRepository,
        private string $trackingApiBaseUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function syncFromPortalConfig(): array
    {
        $cfg = $this->settingsRepository->getApiConfig() ?? [];
        $access = trim((string) ($cfg['lexos_token'] ?? ''));
        $refresh = trim((string) ($cfg['lexos_refresh_token'] ?? ''));
        $key = trim((string) ($cfg['lexos_integration_key'] ?? ''));
        $code = trim((string) ($cfg['lexos_code'] ?? ''));

        if ($refresh === '' && $access === '') {
            throw new RuntimeException(
                'Portal sem token Lexos. Preencha Refresh Token (ou Token) em Configuração API → Lexos.'
            );
        }

        $keyFromTracking = $this->resolveKeyFromTracking();
        if ($key === '' && $keyFromTracking !== '') {
            $key = $keyFromTracking;
        }

        if ($key === '') {
            throw new RuntimeException(
                'Portal sem Chave Lexos (header Chave). Cole a Key do Tracking (Configurações → Credenciais API Lexos) '
                . 'ou configure TRACKING_DATABASE_URL no Render do Portal para reutilizar a chave já salva no Tracking.'
            );
        }

        if ($this->trackingLexosTokenRepository->isAvailable()) {
            $row = $this->trackingLexosTokenRepository->syncFullCredentials(
                $access,
                $refresh,
                $key,
                $code !== '' ? $code : null
            );

            $message = 'Credenciais enviadas ao Tracking via banco PostgreSQL.';
            if ($keyFromTracking !== '' && trim((string) ($cfg['lexos_integration_key'] ?? '')) === '') {
                $message .= ' Key reutilizada do registro já existente no Tracking.';
            }

            return [
                'mode' => 'database',
                'ok' => true,
                'message' => $message,
                'tracking' => $this->trackingLexosTokenRepository->getPublicStatus($row),
            ];
        }

        return $this->syncViaWebhook($access, $refresh, $key, $code);
    }

    private function resolveKeyFromTracking(): string
    {
        if (!$this->trackingLexosTokenRepository->isAvailable()) {
            return '';
        }

        $row = $this->trackingLexosTokenRepository->findLatest();

        return trim((string) ($row['chave'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function syncViaWebhook(string $access, string $refresh, string $key, string $code): array
    {
        $secret = getenv('TRACKING_SYNC_SECRET');
        $secret = is_string($secret) ? trim($secret) : '';
        if ($secret === '') {
            throw new RuntimeException(
                'Configure TRACKING_DATABASE_URL (recomendado) ou TRACKING_SYNC_SECRET + URL do Tracking no Render.'
            );
        }

        $base = rtrim($this->trackingApiBaseUrl, '/');
        if ($base === '') {
            throw new RuntimeException('URL base do Tracking não configurada (TRACKING_PUBLIC_URL).');
        }

        $payload = json_encode([
            'access_token' => $access,
            'refresh_token' => $refresh,
            'chave' => $key,
            'lexos_code' => $code !== '' ? $code : null,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($base . '/api/webhook/sync-lexos-credentials');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Tracking-Sync-Secret: ' . $secret,
            ],
            CURLOPT_TIMEOUT => 45,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            $extra = is_string($raw) && $raw !== '' ? ' ' . substr($raw, 0, 300) : '';
            throw new RuntimeException(
                'Falha ao sincronizar via webhook Tracking. HTTP ' . $status
                . ($error !== '' ? ' ' . $error : '')
                . $extra
            );
        }

        $decoded = json_decode((string) $raw, true);

        return [
            'mode' => 'webhook',
            'ok' => true,
            'message' => is_array($decoded) ? (string) ($decoded['message'] ?? 'Sincronizado.') : 'Sincronizado.',
            'response' => is_array($decoded) ? $decoded : [],
        ];
    }
}
