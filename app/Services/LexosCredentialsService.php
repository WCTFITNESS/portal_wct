<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use App\Repositories\TrackingLexosTokenRepository;

/**
 * Credenciais Lexos: portal (api_settings) e/ou banco do Tracking (lexos_tokens).
 */
final class LexosCredentialsService
{
    public const MODE_AUTO = 'auto';
    public const MODE_PORTAL = 'portal';
    public const MODE_TRACKING = 'tracking';

    public function __construct(
        private SettingsRepository $settingsRepository,
        private TrackingLexosTokenRepository $trackingLexosTokenRepository,
    ) {
    }

    /**
     * Credenciais efetivas para chamadas Lexos Hub / API.
     *
     * @return array{
     *   token: string,
     *   refresh_token: string,
     *   integration_key: string,
     *   integration_header_name: string,
     *   lexos_code: string,
     *   source: string,
     *   tracking_status: array<string, mixed>
     * }
     */
    public function resolve(): array
    {
        $portal = $this->settingsRepository->getApiConfig() ?? [];
        $mode = $this->normalizeMode((string) ($portal['lexos_credentials_mode'] ?? self::MODE_AUTO));

        $portalToken = $this->normalizeBearer((string) ($portal['lexos_token'] ?? ''));
        $portalRefresh = trim((string) ($portal['lexos_refresh_token'] ?? ''));
        $portalKey = trim((string) ($portal['lexos_integration_key'] ?? ''));
        $portalHeader = trim((string) ($portal['lexos_integration_header_name'] ?? ''));
        $portalCode = trim((string) ($portal['lexos_code'] ?? ''));

        $trackingRow = $this->trackingLexosTokenRepository->findLatest();
        $trackingStatus = $this->trackingLexosTokenRepository->getPublicStatus($trackingRow);

        $trackingToken = $this->normalizeBearer((string) ($trackingRow['access_token'] ?? ''));
        $trackingRefresh = trim((string) ($trackingRow['refresh_token'] ?? ''));
        $trackingKey = trim((string) ($trackingRow['chave'] ?? ''));

        $useTracking = match ($mode) {
            self::MODE_TRACKING => $this->trackingLexosTokenRepository->isAvailable(),
            self::MODE_PORTAL => false,
            default => $this->trackingLexosTokenRepository->isAvailable()
                && ($trackingToken !== '' || $trackingKey !== ''),
        };

        if ($useTracking) {
            $token = $trackingToken !== '' ? $trackingToken : $portalToken;
            $refresh = $trackingRefresh !== '' ? $trackingRefresh : $portalRefresh;
            $key = $trackingKey !== '' ? $trackingKey : $portalKey;
            $source = 'tracking';
        } else {
            $token = $portalToken;
            $refresh = $portalRefresh;
            $key = $portalKey;
            $source = 'portal';
        }

        // Campos preenchidos manualmente no portal sempre prevalecem.
        if ($portalToken !== '') {
            $token = $portalToken;
            $source = $useTracking ? 'tracking+portal' : 'portal';
        }
        if ($portalKey !== '') {
            $key = $portalKey;
            $source = $useTracking ? 'tracking+portal' : 'portal';
        }
        if ($portalRefresh !== '') {
            $refresh = $portalRefresh;
        }

        return [
            'token' => $token,
            'refresh_token' => $refresh,
            'integration_key' => $key,
            'integration_header_name' => $portalHeader,
            'lexos_code' => $portalCode,
            'source' => $source,
            'mode' => $mode,
            'tracking_status' => $trackingStatus,
        ];
    }

    public function isReady(): bool
    {
        $c = $this->resolve();

        return $c['token'] !== '' && $c['integration_key'] !== '';
    }

    /**
     * @return array{has_token: bool, has_key: bool, source: string, mode: string, tracking: array<string, mixed>}
     */
    public function getStatusSummary(): array
    {
        $c = $this->resolve();

        return [
            'has_token' => $c['token'] !== '',
            'has_key' => $c['integration_key'] !== '',
            'source' => (string) $c['source'],
            'mode' => (string) $c['mode'],
            'tracking' => $c['tracking_status'],
        ];
    }

    public function getTrackingDatabaseUrl(): string
    {
        $portal = $this->settingsRepository->getApiConfig() ?? [];
        $url = trim((string) ($portal['tracking_database_url'] ?? ''));

        if ($url !== '') {
            return $url;
        }

        $env = getenv('TRACKING_DATABASE_URL');

        return is_string($env) ? trim($env) : '';
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, [self::MODE_AUTO, self::MODE_PORTAL, self::MODE_TRACKING], true)
            ? $mode
            : self::MODE_AUTO;
    }

    private function normalizeBearer(string $token): string
    {
        return preg_replace('/^\s*Bearer\s+/i', '', trim($token)) ?? trim($token);
    }
}
