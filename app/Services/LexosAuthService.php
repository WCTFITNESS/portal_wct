<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use App\Repositories\TrackingLexosTokenRepository;
use RuntimeException;

class LexosAuthService
{
    public function __construct(
        private SettingsRepository $settingsRepository,
        private LexosCredentialsService $lexosCredentialsService,
        private TrackingLexosTokenRepository $trackingLexosTokenRepository,
    ) {
    }

    public function exchangeCodeForToken(?string $code = null): array
    {
        $creds = $this->lexosCredentialsService->resolve();
        $codeValue = $this->normalizeCodeInput((string) ($code ?? ($creds['lexos_code'] ?? '')));
        if ($codeValue === '') {
            throw new RuntimeException('Lexos Code não informado.');
        }
        $refreshFromCfg = trim((string) ($creds['refresh_token'] ?? ''));

        $response = $this->postWithPayloadFallbacks(
            'https://api.lexos.com.br/Autenticacao/token',
            [
                ['code' => $codeValue],
                ['Code' => $codeValue],
                ['authorization_code' => $codeValue],
                ['authorizationCode' => $codeValue],
                ['token' => $codeValue],
                ['refresh_token' => $codeValue],
                ['refreshToken' => $codeValue],
            ]
        );

        $accessToken = $this->extractTokenValue($response, ['access_token', 'accessToken', 'token', 'Token']);
        $refreshToken = $this->extractTokenValue($response, ['refresh_token', 'refreshToken', 'RefreshToken']);
        if ($accessToken === '' && $refreshFromCfg !== '') {
            return $this->refreshLexosToken($refreshFromCfg);
        }
        if ($accessToken === '') {
            throw new RuntimeException('Resposta da Lexos sem access token.');
        }

        $expiresIn = $this->extractExpiresIn($response);
        $this->saveLexosCredentials($codeValue, $accessToken, $refreshToken !== '' ? $refreshToken : null, $expiresIn);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'raw' => $response,
        ];
    }

    public function refreshLexosToken(?string $refreshToken = null): array
    {
        $creds = $this->lexosCredentialsService->resolve();
        $refresh = trim((string) ($refreshToken ?? ($creds['refresh_token'] ?? '')));
        if ($refresh === '') {
            throw new RuntimeException('Refresh token Lexos não informado.');
        }

        $response = $this->postWithPayloadFallbacks(
            'https://api.lexos.com.br/Autenticacao/RefreshToken',
            [
                ['refreshToken' => $refresh],
                ['refresh_token' => $refresh],
                ['RefreshToken' => $refresh],
            ]
        );

        $accessToken = $this->extractTokenValue($response, ['access_token', 'accessToken', 'token', 'Token']);
        $newRefreshToken = $this->extractTokenValue($response, ['refresh_token', 'refreshToken', 'RefreshToken']);
        if ($accessToken === '') {
            throw new RuntimeException('Resposta da Lexos sem access token.');
        }

        $expiresIn = $this->extractExpiresIn($response);
        $this->saveLexosCredentials(
            (string) ($creds['lexos_code'] ?? ''),
            $accessToken,
            $newRefreshToken !== '' ? $newRefreshToken : $refresh,
            $expiresIn
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken !== '' ? $newRefreshToken : $refresh,
            'raw' => $response,
        ];
    }

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

        throw new RuntimeException($lastError !== '' ? $lastError : 'Falha ao consultar autenticação Lexos.');
    }

    private function httpPostJson(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = $this->buildAuthHeaders('application/json');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            $extra = is_string($raw) && $raw !== '' ? ' Resposta: ' . substr($raw, 0, 300) : '';
            throw new RuntimeException('Falha autenticação Lexos. HTTP: ' . $status . ($error !== '' ? ' ' . $error : '') . $extra);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inválida da autenticação Lexos.');
        }

        return $decoded;
    }

    private function httpPostFormUrlEncoded(string $url, array $payload): array
    {
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        $headers = $this->buildAuthHeaders('application/x-www-form-urlencoded');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            $extra = is_string($raw) && $raw !== '' ? ' Resposta: ' . substr($raw, 0, 300) : '';
            throw new RuntimeException('Falha autenticação Lexos. HTTP: ' . $status . ($error !== '' ? ' ' . $error : '') . $extra);
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inválida da autenticação Lexos.');
        }

        return $decoded;
    }

    private function extractTokenValue(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractExpiresIn(array $data): int
    {
        foreach (['expires_in', 'expiresIn', 'ExpiresIn'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                return max(60, (int) $data[$key]);
            }
        }

        return 21600;
    }

    private function saveLexosCredentials(string $code, string $accessToken, ?string $refreshToken, int $expiresIn = 21600): void
    {
        $existing = $this->settingsRepository->getApiConfig() ?? [];
        $this->settingsRepository->saveApiConfig([
            'app_id' => trim((string) ($existing['app_id'] ?? '')),
            'client_secret' => trim((string) ($existing['client_secret'] ?? '')),
            'redirect_uri' => trim((string) ($existing['redirect_uri'] ?? '')),
            'seller_id' => trim((string) ($existing['seller_id'] ?? '')),
            'oauth_code' => trim((string) ($existing['oauth_code'] ?? '')),
            'lexos_code' => trim($code),
            'lexos_token' => trim($accessToken),
            'lexos_refresh_token' => trim((string) ($refreshToken ?? '')),
            'lexos_integration_key' => trim((string) ($existing['lexos_integration_key'] ?? '')),
            'lexos_integration_header_name' => trim((string) ($existing['lexos_integration_header_name'] ?? '')),
            'tracking_database_url' => trim((string) ($existing['tracking_database_url'] ?? '')),
            'lexos_credentials_mode' => trim((string) ($existing['lexos_credentials_mode'] ?? LexosCredentialsService::MODE_AUTO)),
        ]);

        if ($refreshToken !== null && $refreshToken !== '') {
            $this->trackingLexosTokenRepository->updateTokens($accessToken, $expiresIn, $refreshToken);
        }
    }

    /**
     * @return list<string>
     */
    private function buildAuthHeaders(string $contentType): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: ' . $contentType,
        ];

        $integrationKey = trim((string) ($this->lexosCredentialsService->resolve()['integration_key'] ?? ''));
        if ($integrationKey !== '') {
            $customHeaderName = trim((string) ($this->lexosCredentialsService->resolve()['integration_header_name'] ?? ''));
            if ($customHeaderName !== '' && strcasecmp($customHeaderName, 'Chave') !== 0) {
                $headers[] = $customHeaderName . ': ' . $integrationKey;
            }
            $headers[] = 'Chave: ' . $integrationKey;
            $headers[] = 'x-api-key: ' . $integrationKey;
            $headers[] = 'x-integration-key: ' . $integrationKey;
            $headers[] = 'integration-key: ' . $integrationKey;
            $headers[] = 'chave-integracao: ' . $integrationKey;
        }

        return $headers;
    }

    private function normalizeCodeInput(string $input): string
    {
        $value = trim($input);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, 'code=')) {
            $parts = parse_url($value);
            if (is_array($parts) && isset($parts['query'])) {
                parse_str((string) $parts['query'], $query);
                $candidate = trim((string) ($query['code'] ?? ''));
                if ($candidate !== '') {
                    return urldecode($candidate);
                }
            }
        }

        return urldecode($value);
    }
}
