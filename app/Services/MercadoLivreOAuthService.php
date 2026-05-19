<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RequestLogRepository;

class MercadoLivreOAuthService
{
    private const API_BASE = 'https://api.mercadolibre.com';
    private const AUTH_BASE_BR = 'https://auth.mercadolivre.com.br';

    public function __construct(private RequestLogRepository $requestLogRepository)
    {
    }

    public function buildAuthUrl(string $appId, string $redirectUri): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->normalizeCredential($appId),
            'redirect_uri' => $this->normalizeCredential($redirectUri),
        ]);

        return self::AUTH_BASE_BR . '/authorization?' . $params;
    }

    public function exchangeCode(
        string $appId,
        string $clientSecret,
        string $redirectUri,
        string $code
    ): array {
        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->normalizeCredential($appId),
            'client_secret' => $this->normalizeCredential($clientSecret),
            'code' => $this->normalizeOauthCode($code),
            'redirect_uri' => $this->normalizeCredential($redirectUri),
        ];

        if ($payload['client_id'] === '' || $payload['client_secret'] === '') {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'missing_credentials',
                    'error_description' => 'App ID e Client Secret são obrigatórios.',
                ],
                'raw' => '',
            ];
        }

        if ($payload['code'] === '') {
            return [
                'status' => 400,
                'body' => [
                    'error' => 'missing_code',
                    'error_description' => 'Code OAuth não informado ou inválido.',
                ],
                'raw' => '',
            ];
        }

        $result = $this->postToken($payload, 'application/x-www-form-urlencoded');
        if (
            $result['status'] === 400
            && is_array($result['body'])
            && ($result['body']['error'] ?? '') === 'invalid_client'
        ) {
            return $this->postToken($payload, 'application/json');
        }

        return $result;
    }

    public function oauthErrorHint(array $body): string
    {
        $err = trim((string) ($body['error'] ?? ''));
        $desc = trim((string) ($body['error_description'] ?? ''));

        if ($err === 'invalid_client') {
            return 'App ID ou Client Secret inválidos (ou de apps diferentes). Verifique no Mercado Livre Developers e cole sem espaços extras.';
        }
        if ($err === 'invalid_grant') {
            return 'Code inválido, expirado, já usado ou Redirect URI diferente da autorização. Gere um code novo com o mesmo Redirect URI do app.';
        }

        return $desc !== '' ? $desc : ($err !== '' ? $err : 'Erro OAuth');
    }

    public function normalizeOauthCode(string $value): string
    {
        $raw = $this->normalizeCredential($value);
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, '://') || str_starts_with($raw, '?')) {
            $url = str_contains($raw, '://') ? $raw : 'https://local.invalid/?' . ltrim($raw, '?');
            $query = parse_url($url, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                if (!empty($params['code'])) {
                    return urldecode((string) $params['code']);
                }
            }
        }

        if (stripos($raw, 'code=') !== false) {
            $fragment = explode('code=', $raw, 2)[1];

            return urldecode(explode('&', $fragment, 2)[0]);
        }

        return urldecode(explode('&', $raw, 2)[0]);
    }

    private function normalizeCredential(string $value): string
    {
        $text = trim($value);
        foreach (["\u{FEFF}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{00A0}"] as $ch) {
            $text = str_replace($ch, '', $text);
        }

        return trim($text);
    }

    private function postToken(array $payload, string $contentType): array
    {
        $url = self::API_BASE . '/oauth/token';

        if ($contentType === 'application/json') {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $headers = ['Content-Type: application/json', 'Accept: application/json'];
        } else {
            $body = http_build_query($payload);
            $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->requestLogRepository->register('POST', '/oauth/token', null, $body, null, $error);

            throw new \RuntimeException('Erro cURL ao trocar code: ' . $error);
        }

        $this->requestLogRepository->register('POST', '/oauth/token', $httpCode, $body, $response);

        return [
            'status' => $httpCode,
            'body' => json_decode($response, true) ?? [],
            'raw' => $response,
        ];
    }
}
