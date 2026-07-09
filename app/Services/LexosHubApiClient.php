<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

class LexosHubApiClient
{
    public const AUTH_INTEGRATION = 'integration';
    public const AUTH_HUB = 'hub';
    public const AUTH_AUTO = 'auto';

    public function __construct(
        private LexosCredentialsService $lexosCredentialsService,
        private LexosAuthService $lexosAuthService,
        private ?LexosHubSessionService $lexosHubSessionService = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array{ok: bool, status: int, body: mixed, error: string, auth: string}
     */
    public function request(string $method, string $url, ?array $payload = null, string $authMode = self::AUTH_INTEGRATION): array
    {
        $this->assertLexosCredentials($authMode);
        if ($authMode !== self::AUTH_HUB) {
            $this->maybeRefreshExpiredToken();
        }

        try {
            return $this->executeRequest($method, $url, $payload, $authMode);
        } catch (RuntimeException $exception) {
            if ($authMode === self::AUTH_HUB && $this->isUnauthorized($exception)) {
                if ($this->lexosHubSessionService !== null && $this->lexosHubSessionService->refreshHubAccessToken()) {
                    try {
                        return $this->executeRequest($method, $url, $payload, $authMode);
                    } catch (RuntimeException $retryException) {
                        throw $this->wrapFailure($retryException, null, $authMode);
                    }
                }
            }

            if ($authMode === self::AUTH_HUB || !$this->isUnauthorized($exception)) {
                throw $this->wrapFailure($exception, null, $authMode);
            }

            try {
                $this->renewLexosAccessToken();
            } catch (Throwable $refreshException) {
                throw $this->wrapFailure($exception, $refreshException, $authMode);
            }

            try {
                return $this->executeRequest($method, $url, $payload, $authMode);
            } catch (RuntimeException $retryException) {
                throw $this->wrapFailure($retryException, null, $authMode);
            }
        }
    }

    /**
     * Diagnóstico das credenciais contra a Lexos WebAPI (endpoint leve).
     *
     * @return array<string, mixed>
     */
    public function probeHubApi(): array
    {
        $creds = $this->lexosCredentialsService->resolve();
        $out = [
            'ready' => $this->lexosCredentialsService->hasHubToken()
                || $this->lexosCredentialsService->getHubRefreshToken() !== '',
            'integration_ready' => $this->lexosCredentialsService->isReady(),
            'hub_token_preview' => $this->lexosCredentialsService->getHubStatusSummary()['hub_token_preview'],
            'key_preview' => self::maskSecret((string) ($creds['integration_key'] ?? '')),
            'refresh_attempted' => false,
            'refresh_ok' => false,
            'refresh_error' => '',
            'hub_ok' => false,
            'hub_http' => 0,
            'hub_auth' => '',
            'hub_rows' => 0,
            'hub_error' => '',
        ];

        if (!$out['ready']) {
            $out['hub_error'] = 'Token Hub ausente. Instale o conector Lexos (Configuração API) ou sincronize via app-hub.lexos.com.br.';

            return $out;
        }

        try {
            $this->ensureHubSession();
        } catch (Throwable $e) {
            $out['hub_error'] = $e->getMessage();

            return $out;
        }

        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $monthStart = (new \DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $url = "https://app-hub-webapi.lexos.com.br/api/RelatorioVendas/DataSourceCurvaAbc?lojaId=-1&initialDate={$monthStart}T00:00:00&finalDate={$today}T23:59:59";
        $payload = [
            'requiresCounts' => true,
            'aggregates' => [['type' => 'sum', 'field' => 'TotalVendidoItem']],
            'skip' => 0,
            'take' => 1,
        ];

        try {
            $result = $this->request('POST', $url, $payload, self::AUTH_HUB);
            $out['hub_ok'] = (bool) ($result['ok'] ?? false);
            $out['hub_http'] = (int) ($result['status'] ?? 0);
            $out['hub_auth'] = (string) ($result['auth'] ?? '');
            $body = $result['body'] ?? null;
            if (is_array($body)) {
                if (isset($body['result']) && is_array($body['result'])) {
                    $out['hub_rows'] = count($body['result']);
                } elseif ($body !== [] && array_keys($body) === range(0, count($body) - 1)) {
                    $out['hub_rows'] = count($body);
                }
            }
        } catch (Throwable $e) {
            $out['hub_error'] = $e->getMessage();
            if (preg_match('/HTTP:\s*(\d+)/', $e->getMessage(), $m)) {
                $out['hub_http'] = (int) $m[1];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $payload, string $authMode = self::AUTH_INTEGRATION): array
    {
        $result = $this->request('POST', $url, $payload, $authMode);
        if (!$result['ok']) {
            throw new RuntimeException(
                'Falha consulta Lexos WebAPI. HTTP: ' . $result['status']
                . ($result['error'] !== '' ? ' ' . $result['error'] : '')
            );
        }

        return is_array($result['body']) ? $result['body'] : [];
    }

    /**
     * Produtos/SKU: exige Token Hub (sessão app-hub.lexos.com.br).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postJsonDashboard(string $url, array $payload): array
    {
        $this->ensureHubSession();

        return $this->postJson($url, $payload, self::AUTH_HUB);
    }

    private function ensureHubSession(): void
    {
        if ($this->lexosHubSessionService === null) {
            if (!$this->lexosCredentialsService->hasHubToken()) {
                throw new RuntimeException(
                    'Token Hub ausente. Instale o conector Lexos em Configuração API → Lexos.'
                );
            }

            return;
        }

        $this->lexosHubSessionService->ensureValidHubToken();
    }

    private function assertLexosCredentials(string $authMode = self::AUTH_INTEGRATION): void
    {
        if ($authMode === self::AUTH_HUB) {
            if ($this->lexosCredentialsService->hasHubToken()) {
                return;
            }

            throw new RuntimeException(
                'Token Hub ausente. Abra app-hub.lexos.com.br logado, copie localStorage.access_token '
                . 'e cole em Configuração API → Lexos → Token Hub (Dashboard). '
                . 'Não use o token OAuth do Tracking — é outro tipo de credencial.'
            );
        }

        if ($authMode === self::AUTH_AUTO && ($this->lexosCredentialsService->hasHubToken() || $this->lexosCredentialsService->isReady())) {
            return;
        }

        if ($authMode === self::AUTH_INTEGRATION && $this->lexosCredentialsService->isReady()) {
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
    private function executeRequest(string $method, string $url, ?array $payload, string $authMode): array
    {
        $body = $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : '';
        $authAttempts = $this->buildAuthAttempts($authMode);

        $last = [
            'ok' => false,
            'status' => 0,
            'body' => null,
            'error' => 'Erro desconhecido',
            'auth' => '',
        ];

        foreach ($authAttempts as $attempt) {
            [$status, $err, $raw] = $this->curlRequest($method, $url, $body, $attempt['headers']);
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
                'auth' => (string) $attempt['label'],
            ];

            if ($last['ok']) {
                return $last;
            }

            if ($status !== 401) {
                $extra = is_string($raw) && $raw !== '' ? ' Resposta: ' . substr($raw, 0, 400) : '';

                throw new RuntimeException(
                    'Falha consulta Lexos WebAPI. HTTP: ' . $status
                    . ' Tentativa: ' . $attempt['label']
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
     * @return list<array{label: string, headers: list<string>}>
     */
    private function buildAuthAttempts(string $authMode): array
    {
        $attempts = [];

        if ($authMode === self::AUTH_HUB || $authMode === self::AUTH_AUTO) {
            $hubToken = $this->getHubAccessToken();
            if ($hubToken !== '') {
                $attempts[] = [
                    'label' => 'hub_bearer',
                    'headers' => [
                        'Accept: application/json',
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $hubToken,
                    ],
                ];
            }
        }

        if ($authMode === self::AUTH_INTEGRATION || $authMode === self::AUTH_AUTO) {
            $token = $this->getIntegrationToken();
            $integrationKey = $this->getLexosIntegrationKey();
            if ($token !== '') {
                if ($authMode === self::AUTH_AUTO) {
                    $attempts[] = [
                        'label' => 'integration_bearer_only',
                        'headers' => [
                            'Accept: application/json',
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $token,
                        ],
                    ];
                }
                if ($integrationKey !== '') {
                    $baseHeaders = $this->buildIntegrationHeaders();
                    $attempts[] = [
                        'label' => 'integration_bearer',
                        'headers' => array_merge($baseHeaders, ['Authorization: Bearer ' . $token]),
                    ];
                    $attempts[] = [
                        'label' => 'integration_raw',
                        'headers' => array_merge($baseHeaders, ['Authorization: ' . $token]),
                    ];
                }
            }
        }

        if ($attempts === []) {
            throw new RuntimeException('Token Lexos não configurado.');
        }

        return $attempts;
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
            'Cache-Control: no-cache',
            'Chave: ' . $integrationKey,
            'chave: ' . $integrationKey,
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

    private function getHubAccessToken(): string
    {
        return $this->lexosCredentialsService->getHubAccessToken();
    }

    private function getIntegrationToken(): string
    {
        $token = trim((string) ($this->lexosCredentialsService->resolve()['token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Token de integração Lexos não configurado.');
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

    private function wrapFailure(RuntimeException $exception, ?Throwable $refreshException = null, string $authMode = self::AUTH_INTEGRATION): RuntimeException
    {
        $message = $exception->getMessage();
        if ($this->isUnauthorized($exception)) {
            if ($authMode === self::AUTH_HUB) {
                $message .= ' Produtos/SKU exigem Token Hub do app-hub.lexos.com.br. '
                    . 'Instale o conector Lexos (Configuração API → Lexos) para sincronizar automaticamente.';
            } elseif ($authMode === self::AUTH_AUTO) {
                $message .= ' Verifique Token Hub ou credenciais de integração Lexos.';
            } else {
                $message .= ' Verifique Token Lexos, chave de integração (header Chave) e execute Refresh Token Lexos em Configuração API.';
            }
            if ($refreshException !== null) {
                $message .= ' Falha ao renovar token automaticamente: ' . $refreshException->getMessage();
            } elseif ($authMode === self::AUTH_INTEGRATION) {
                $message .= ' Se o refresh já foi tentado, a causa mais provável é a Chave de integração incorreta — use Importar Key do Tracking ou renove o Code na Lexos.';
            }
        }

        return new RuntimeException($message, 0, $exception);
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
