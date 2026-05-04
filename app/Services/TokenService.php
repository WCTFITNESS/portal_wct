<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use App\Repositories\TokenRepository;

class TokenService
{
    public function __construct(
        private SettingsRepository $settingsRepository,
        private TokenRepository $tokenRepository,
        private MercadoLivreClient $client
    ) {
    }

    public function getValidAccessToken(): string
    {
        $token = $this->tokenRepository->getLatestToken();

        if (!$token) {
            throw new \RuntimeException('Token não configurado. Cadastre o token inicial na tela de API.');
        }

        $expiresAt = strtotime((string) $token['expires_at']);
        $willExpireSoon = $expiresAt <= (time() + 120);

        if ($willExpireSoon) {
            $this->refreshToken();
            $token = $this->tokenRepository->getLatestToken();
        }

        return (string) $token['access_token'];
    }

    public function saveInitialToken(array $tokenData): void
    {
        $expiresIn = (int) $tokenData['expires_in'];
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

        $this->tokenRepository->saveToken([
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'],
            'expires_in' => $expiresIn,
            'expires_at' => $expiresAt,
            'token_type' => $tokenData['token_type'] ?? 'Bearer',
            'scope' => $tokenData['scope'] ?? null,
        ]);
    }

    public function refreshToken(): void
    {
        $apiConfig = $this->settingsRepository->getApiConfig();
        $token = $this->tokenRepository->getLatestToken();

        if (!$apiConfig || !$token) {
            throw new \RuntimeException('Configuração de API ou token ausente para refresh.');
        }

        $result = $this->client->refreshToken(
            (string) $apiConfig['app_id'],
            (string) $apiConfig['client_secret'],
            (string) $token['refresh_token']
        );

        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new \RuntimeException(
                'Falha no refresh token. Resposta: ' . ($result['raw'] ?? 'sem detalhes')
            );
        }

        $this->saveInitialToken($result['body']);
    }
}
