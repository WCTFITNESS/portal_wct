<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Extrai access/refresh e metadados do Hub a partir de localStorage/cookies capturados.
 */
final class LexosHubContextParser
{
    /** @var list<string> */
    private const ACCESS_KEYS = [
        'access_token',
        'accessToken',
        'token',
        'authToken',
        'hub_access_token',
        'hubAccessToken',
    ];

    /** @var list<string> */
    private const REFRESH_KEYS = [
        'refresh_token',
        'refreshToken',
        'hub_refresh_token',
        'hubRefreshToken',
    ];

    /**
     * @param array<string, mixed> $context
     * @return array{
     *   access: string,
     *   refresh: string,
     *   storage: array<string, string>,
     *   session_storage: array<string, string>,
     *   cookies: string,
     *   captured_at: int
     * }
     */
    public function normalizeContext(array $context): array
    {
        $storage = $this->normalizeStringMap($context['local_storage'] ?? $context['storage'] ?? []);
        $sessionStorage = $this->normalizeStringMap($context['session_storage'] ?? []);
        $cookies = trim((string) ($context['cookies'] ?? ''));

        return [
            'access' => '',
            'refresh' => '',
            'storage' => $storage,
            'session_storage' => $sessionStorage,
            'cookies' => $cookies,
            'captured_at' => (int) ($context['captured_at'] ?? time()),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{access: string, refresh: string, context: array<string, mixed>}
     */
    public function merge(string $access, string $refresh, array $context = []): array
    {
        $normalized = $this->normalizeContext($context);
        $access = $this->normalizeBearer($access);
        $refresh = trim($refresh);

        if ($access === '') {
            $access = $this->findTokenInMaps(self::ACCESS_KEYS, $normalized['storage'], $normalized['session_storage']);
        }
        if ($refresh === '') {
            $refresh = $this->findTokenInMaps(self::REFRESH_KEYS, $normalized['storage'], $normalized['session_storage']);
        }

        $normalized['access'] = $access;
        $normalized['refresh'] = $refresh;

        return [
            'access' => $access,
            'refresh' => $refresh,
            'context' => [
                'local_storage' => $normalized['storage'],
                'session_storage' => $normalized['session_storage'],
                'cookies' => $normalized['cookies'],
                'captured_at' => $normalized['captured_at'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public function extractAccessFromStoredContext(array $context): string
    {
        $merged = $this->merge('', '', $context);

        return $merged['access'];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private function normalizeStringMap(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($value) || is_numeric($value)) {
                $out[$key] = trim((string) $value);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $keys
     * @param array<string, string> $storage
     * @param array<string, string> $sessionStorage
     */
    private function findTokenInMaps(array $keys, array $storage, array $sessionStorage): string
    {
        foreach ([$storage, $sessionStorage] as $map) {
            foreach ($keys as $key) {
                $value = trim((string) ($map[$key] ?? ''));
                if ($value !== '') {
                    return $this->normalizeBearer($value);
                }
            }
            foreach ($map as $value) {
                $nested = $this->extractFromJsonBlob($value, $keys);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    /**
     * @param list<string> $keys
     */
    private function extractFromJsonBlob(string $raw, array $keys): string
    {
        $raw = trim($raw);
        if ($raw === '' || ($raw[0] !== '{' && $raw[0] !== '[')) {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }

        foreach ($keys as $key) {
            $value = trim((string) ($decoded[$key] ?? ''));
            if ($value !== '') {
                return $this->normalizeBearer($value);
            }
        }

        foreach (['data', 'result', 'token', 'auth', 'session'] as $nested) {
            $child = $decoded[$nested] ?? null;
            if (!is_array($child)) {
                continue;
            }
            foreach ($keys as $key) {
                $value = trim((string) ($child[$key] ?? ''));
                if ($value !== '') {
                    return $this->normalizeBearer($value);
                }
            }
        }

        return '';
    }

    private function normalizeBearer(string $token): string
    {
        return preg_replace('/^\s*Bearer\s+/i', '', trim($token)) ?? trim($token);
    }
}
