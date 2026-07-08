<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

class LexosHubApiClient
{
    public function __construct(
        private LexosCredentialsService $lexosCredentialsService,
        private LexosAuthService $lexosAuthService,
    ) {
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{ok: bool, status: int, body: mixed, error: string, auth: string}
     */
    public function request(string $method, string $url, ?array $payload = null): array
    {
        $this->assertLexosCredentials();
        $this->maybeRefreshExpiredToken();

        try {
            return $this->executeRequest($method, $url, $payload);
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
                return $this->executeRequest($method, $url, $payload);
            } catch (RuntimeException $retryException) {
                throw $this->wrapFailure($retryException);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $payload): array
    {
        $result = $this->request('POST', $url, $payload);
        if (!$result['ok']) {
            throw new RuntimeException(
                'Falha consulta Lexos WebAPI. HTTP: ' . $result['status']
                . ($result['error'] !== '' ? ' ' . $result['error'] : '')
            );
        }

        return is_array($result['body']) ? $result['body'] : [];
    }

    private function assertLexosCredentials(): void
    {
        if ($this->lexosCredentialsService->isReady()) {
            return;
        }

        $status = $this->lexosCredentialsService->getStatusSummary();
        $tracking = $status['tracking'];
        if (($tracking['connected'] ?? false) && !($tracking['has_row'] ?? false)) {
            throw new RuntimeException(
                'Banco Tracking conectado, mas não há registro em lexos_tokens. Configure as credenciais no Tracking ou preencha Token e Chave na aba Lexos.'
            );
        }

        throw new RuntimeException(
            'Credenciais Lexos incompletas. Configure Token e chave de integração (header Chave) na aba Lexos ou conecte o banco do Tracking com tokens salvos.'
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{ok: bool, status: int, body: mixed, error: string, auth: string}
     */
    private function executeRequest(string $method, string $url, ?array $payload): array
    {
        $token = $this->getLexosToken();
        $body = $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
        $baseHeaders = $this->buildIntegrationHeaders();
        $authVariants = [
            ['Authorization: Bearer ' . $token, 'authorization_bearer'],
            ['Authorization: ' . $token, 'authorization_raw'],
        ];

        $last = [
            'ok' => false,
            'status' => 0,
            'body' => null,
            'error' => 'Erro desconhecido',
            'auth' => '',
        ];

        foreach ($authVariants as [$authHeader, $label]) {
            [$status, $err, $raw] = $this->curlRequest($method, $url, $body, array_merge($baseHeaders, [$authHeader]));
            $decoded = null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    $decoded = $raw;
                }
            }

            $last = [
                'ok' => $raw !== false && $status >= 200 && $status < 300,
                'status' => $status,
                'body' => $decoded,
                'error' => $err,
                'auth' => $label,
            ];

            if ($last['ok']) {
                return $last;
            }

            if ($status !== 401) {
                $extra = is_string($raw) && $raw !== '' ? ' Resposta: ' . substr($raw, 0, 400) : '';

                throw new RuntimeException(
                    'Falha consulta Lexos WebAPI. HTTP: ' . $status
                    . ' Tentativa: ' . $label
                    . ($err !== '' ? ' ' . $err : '')
                    . $extra
                );
            }
        }

        $extra = is_string($last['body'] ?? null) ? ' Resposta: ' . substr((string) $last['body'], 0, 400) : '';

        throw new RuntimeException(
            'Falha consulta Lexos WebAPI. HTTP: ' . $last['status']
            . ($last['auth'] !== '' ? ' Tentativa: ' . $last['auth'] : '')
            . ($last['error'] !== '' ? ' ' . $last['error'] : '')
            . $extra
        );
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
    private function curlRequest(string $method, string $url, string $body, array $headers): array
    {
        $method = strtoupper($method);
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ];
        if ($body !== '' && $method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return [$status, $err, $raw];
    }

    private function maybeRefreshExpiredToken(): void
    {
        if (!$this->lexosCredentialsService->shouldRefreshAccessToken()) {
            return;
        }

        try {
            $this->renewLexosAccessToken();
        } catch (Throwable) {
            // Deixa a requisição tentar; em 401 o fluxo de retry renova de novo.
        }
    }

    private function renewLexosAccessToken(): void
    {
        $creds = $this->lexosCredentialsService->resolve();
        $refreshToken = trim((string) ($creds['refresh_token'] ?? ''));
        if ($refreshToken !== '') {
            $this->lexosAuthService->refreshLexosToken($refreshToken);

            return;
        }

        $code = trim((string) ($creds['lexos_code'] ?? ''));
        if ($code !== '') {
            $this->lexosAuthService->exchangeCodeForToken($code);

            return;
        }

        throw new RuntimeException('Refresh token e Lexos Code não configurados para renovar o access token.');
    }

    private function getLexosToken(): string
    {
        $token = trim((string) ($this->lexosCredentialsService->resolve()['token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Token Lexos não configurado.');
        }

        return $token;
    }

    private function getLexosIntegrationKey(): string
    {
        return trim((string) ($this->lexosCredentialsService->resolve()['integration_key'] ?? ''));
    }

    private function getLexosIntegrationHeaderName(): string
    {
        return trim((string) ($this->lexosCredentialsService->resolve()['integration_header_name'] ?? ''));
    }

    private function isUnauthorized(RuntimeException $exception): bool
    {
        return str_contains($exception->getMessage(), 'HTTP: 401');
    }

    private function wrapFailure(RuntimeException $exception, ?Throwable $refreshException = null): RuntimeException
    {
        $message = $exception->getMessage();
        if ($this->isUnauthorized($exception)) {
            $message .= ' Verifique Token Lexos, chave de integração (header Chave) e execute Refresh Token Lexos em Configuração API.';
            if ($refreshException !== null) {
                $message .= ' Falha ao renovar token automaticamente: ' . $refreshException->getMessage();
            }
        }

        return new RuntimeException($message, 0, $exception);
    }
}
