<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;
use Throwable;

class LexosHubApiClient
{
    public function __construct(
        private SettingsRepository $settingsRepository,
        private LexosAuthService $lexosAuthService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $payload): array
    {
        $this->assertLexosCredentials();

        try {
            return $this->executePost($url, $payload);
        } catch (RuntimeException $exception) {
            if (!$this->isUnauthorized($exception)) {
                throw $this->wrapFailure($exception);
            }

            try {
                $this->renewLexosAccessToken();
            } catch (Throwable $refreshException) {
                throw $this->wrapFailure($exception, $refreshException);
            }

            try {
                return $this->executePost($url, $payload);
            } catch (RuntimeException $retryException) {
                throw $this->wrapFailure($retryException);
            }
        }
    }

    private function assertLexosCredentials(): void
    {
        $cfg = $this->settingsRepository->getApiConfig() ?? [];
        $token = trim((string) ($cfg['lexos_token'] ?? ''));
        $integrationKey = trim((string) ($cfg['lexos_integration_key'] ?? ''));

        if ($token === '') {
            throw new RuntimeException('Token Lexos não configurado em Configuração API.');
        }

        if ($integrationKey === '') {
            throw new RuntimeException(
                'Chave segura da integração Lexos não configurada em Configuração API. A Lexos exige o header Chave em todas as requisições.'
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executePost(string $url, array $payload): array
    {
        $token = $this->getLexosToken();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $baseHeaders = $this->buildIntegrationHeaders();
        $authVariants = [
            ['Authorization: Bearer ' . $token, 'authorization_bearer'],
            ['Authorization: ' . $token, 'authorization_raw'],
        ];

        $lastError = null;
        foreach ($authVariants as [$authHeader, $label]) {
            [$status, $err, $raw] = $this->curlPost($url, $body, array_merge($baseHeaders, [$authHeader]));
            if ($raw !== false && $status >= 200 && $status < 300) {
                $decoded = json_decode((string) $raw, true);

                return is_array($decoded) ? $decoded : [];
            }

            $extra = '';
            if (is_string($raw) && $raw !== '') {
                $extra = ' Resposta: ' . substr($raw, 0, 400);
            }

            $lastError = new RuntimeException(
                'Falha consulta Lexos WebAPI. HTTP: ' . $status
                . ' Tentativa: ' . $label
                . ($err !== '' ? ' ' . $err : '')
                . $extra
            );
            if ($status !== 401) {
                throw $lastError;
            }
        }

        if ($lastError !== null) {
            throw $lastError;
        }

        throw new RuntimeException('Falha consulta Lexos WebAPI. Erro desconhecido.');
    }

    /**
     * @return list<string>
     */
    private function buildIntegrationHeaders(): array
    {
        $integrationKey = $this->getLexosIntegrationKey();
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Chave: ' . $integrationKey,
        ];

        $customHeaderName = $this->getLexosIntegrationHeaderName();
        if ($customHeaderName !== '' && strcasecmp($customHeaderName, 'Chave') !== 0) {
            $headers[] = $customHeaderName . ': ' . $integrationKey;
        }

        $headers[] = 'x-api-key: ' . $integrationKey;
        $headers[] = 'x-integration-key: ' . $integrationKey;
        $headers[] = 'integration-key: ' . $integrationKey;
        $headers[] = 'chave-integracao: ' . $integrationKey;

        return $headers;
    }

    /**
     * @param list<string> $headers
     * @return array{int,string,string|false}
     */
    private function curlPost(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return [$status, $err, $raw];
    }

    private function renewLexosAccessToken(): void
    {
        $cfg = $this->settingsRepository->getApiConfig() ?? [];
        $refreshToken = trim((string) ($cfg['lexos_refresh_token'] ?? ''));
        if ($refreshToken !== '') {
            $this->lexosAuthService->refreshLexosToken($refreshToken);

            return;
        }

        $code = trim((string) ($cfg['lexos_code'] ?? ''));
        if ($code !== '') {
            $this->lexosAuthService->exchangeCodeForToken($code);

            return;
        }

        throw new RuntimeException('Refresh token e Lexos Code não configurados para renovar o access token.');
    }

    private function getLexosToken(): string
    {
        $cfg = $this->settingsRepository->getApiConfig();
        $token = trim((string) ($cfg['lexos_token'] ?? ''));
        $token = preg_replace('/^\s*Bearer\s+/i', '', $token) ?? $token;
        if ($token === '') {
            throw new RuntimeException('Token Lexos não configurado em Configuração API.');
        }

        return $token;
    }

    private function getLexosIntegrationKey(): string
    {
        $cfg = $this->settingsRepository->getApiConfig();

        return trim((string) ($cfg['lexos_integration_key'] ?? ''));
    }

    private function getLexosIntegrationHeaderName(): string
    {
        $cfg = $this->settingsRepository->getApiConfig();

        return trim((string) ($cfg['lexos_integration_header_name'] ?? ''));
    }

    private function isUnauthorized(RuntimeException $exception): bool
    {
        return str_contains($exception->getMessage(), 'HTTP: 401');
    }

    private function wrapFailure(RuntimeException $exception, ?Throwable $refreshException = null): RuntimeException
    {
        $message = $exception->getMessage();
        if ($this->isUnauthorized($exception)) {
            $message .= ' Verifique em Configuração API o Token Lexos, a chave segura (header Chave) e execute Refresh Token Lexos.';
            if ($refreshException !== null) {
                $message .= ' Falha ao renovar token automaticamente: ' . $refreshException->getMessage();
            }
        }

        return new RuntimeException($message, 0, $exception);
    }
}
