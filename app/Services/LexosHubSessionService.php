<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;
use Throwable;

/**
 * Mantém o Token Hub (sessão app-hub.lexos.com.br) válido no servidor.
 * Configuração única (TI/Render); usuários finais não precisam instalar nada.
 */
final class LexosHubSessionService
{
    public function __construct(
        private SettingsRepository $settingsRepository,
        private LexosCredentialsService $lexosCredentialsService,
    ) {
    }

    /**
     * Renova silenciosamente em todo request do portal (sem travar a página).
     */
    public function maintainHubSessionSilently(): void
    {
        try {
            $this->maintainHubSession();
        } catch (Throwable) {
            // Token ausente ou refresh falhou — dashboard mostra mensagem amigável.
        }
    }

    public function maintainHubSession(): bool
    {
        $token = $this->lexosCredentialsService->getHubAccessToken();
        if ($token !== '' && !$this->isJwtExpired($token)) {
            return true;
        }

        return $this->refreshHubAccessToken();
    }

    public function ensureValidHubToken(): void
    {
        if ($this->maintainHubSession()) {
            return;
        }

        throw new RuntimeException(
            'A aba Produtos está temporariamente indisponível. '
            . 'Peça ao suporte para concluir a configuração do Lexos Hub (feita uma única vez).'
        );
    }

    public function refreshHubAccessToken(): bool
    {
        $refresh = $this->lexosCredentialsService->getHubRefreshToken();
        if ($refresh === '') {
            return false;
        }

        $urls = [
            'https://api.lexos.com.br/Autenticacao/RefreshToken',
            'https://app-hub-webapi.lexos.com.br/Autenticacao/RefreshToken',
        ];
        $payloads = [
            ['refreshToken' => $refresh],
            ['refresh_token' => $refresh],
            ['RefreshToken' => $refresh],
        ];

        foreach ($urls as $url) {
            foreach ($payloads as $payload) {
                try {
                    $response = $this->postWithPayloadFallbacks($url, [$payload]);
                    $access = $this->extractTokenDeep($response, ['access_token', 'accessToken', 'token', 'Token']);
                    if ($access === '') {
                        continue;
                    }
                    $newRefresh = $this->extractTokenDeep($response, ['refresh_token', 'refreshToken', 'RefreshToken']);
                    $this->persistHubTokens($access, $newRefresh !== '' ? $newRefresh : $refresh);

                    return true;
                } catch (RuntimeException) {
                    continue;
                }
            }
        }

        return false;
    }

    public function persistHubTokens(string $accessToken, string $refreshToken = ''): void
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            return;
        }

        $existing = $this->settingsRepository->getApiConfig() ?? [];
        $this->settingsRepository->saveApiConfig([
            'app_id' => trim((string) ($existing['app_id'] ?? '')),
            'client_secret' => trim((string) ($existing['client_secret'] ?? '')),
            'redirect_uri' => trim((string) ($existing['redirect_uri'] ?? '')),
            'seller_id' => trim((string) ($existing['seller_id'] ?? '')),
            'oauth_code' => trim((string) ($existing['oauth_code'] ?? '')),
            'lexos_code' => trim((string) ($existing['lexos_code'] ?? '')),
            'lexos_hub_token' => $accessToken,
            'lexos_hub_refresh_token' => $refreshToken !== '' ? $refreshToken : trim((string) ($existing['lexos_hub_refresh_token'] ?? '')),
            'lexos_token' => trim((string) ($existing['lexos_token'] ?? '')),
            'lexos_refresh_token' => trim((string) ($existing['lexos_refresh_token'] ?? '')),
            'lexos_integration_key' => trim((string) ($existing['lexos_integration_key'] ?? '')),
            'lexos_integration_header_name' => trim((string) ($existing['lexos_integration_header_name'] ?? '')),
            'tracking_database_url' => trim((string) ($existing['tracking_database_url'] ?? '')) !== '' ? trim((string) $existing['tracking_database_url']) : null,
            'lexos_credentials_mode' => trim((string) ($existing['lexos_credentials_mode'] ?? 'auto')),
        ]);
    }

    private function isJwtExpired(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return false;
        }
        $payloadRaw = strtr($parts[1], '-_', '+/');
        $pad = strlen($payloadRaw) % 4;
        if ($pad > 0) {
            $payloadRaw .= str_repeat('=', 4 - $pad);
        }
        $payload = json_decode((string) base64_decode($payloadRaw), true);
        if (!is_array($payload) || !isset($payload['exp'])) {
            return false;
        }

        // Renova ~30 min antes de expirar (sessão sempre pronta para o usuário).
        return time() >= ((int) $payload['exp'] - 1800);
    }

    /**
     * @param array<string, mixed> $response
     * @param list<string> $keys
     */
    private function extractTokenDeep(array $response, array $keys): string
    {
        foreach ($keys as $key) {
            $val = trim((string) ($response[$key] ?? ''));
            if ($val !== '') {
                return $val;
            }
        }

        foreach (['data', 'result', 'tokenData', 'TokenData'] as $nested) {
            $child = $response[$nested] ?? null;
            if (!is_array($child)) {
                continue;
            }
            foreach ($keys as $key) {
                $val = trim((string) ($child[$key] ?? ''));
                if ($val !== '') {
                    return $val;
                }
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $payloads
     * @return array<string, mixed>
     */
    private function postWithPayloadFallbacks(string $url, array $payloads): array
    {
        $lastError = '';
        foreach ($payloads as $payload) {
            try {
                return $this->httpPostJson($url, $payload);
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
            }
            try {
                return $this->httpPostFormUrlEncoded($url, $payload);
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
            }
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'Falha ao renovar Token Hub.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function httpPostJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP ' . $status);
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function httpPostFormUrlEncoded(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('HTTP ' . $status);
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
