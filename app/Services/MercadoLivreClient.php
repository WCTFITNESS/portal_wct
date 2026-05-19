<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RequestLogRepository;

class MercadoLivreClient
{
    private const API_BASE = 'https://api.mercadolibre.com';

    public function __construct(private RequestLogRepository $requestLogRepository)
    {
    }

    public function post(string $path, array $payload, string $accessToken): array
    {
        return $this->request('POST', $path, $accessToken, $payload);
    }

    public function get(string $path, string $accessToken): array
    {
        return $this->request('GET', $path, $accessToken);
    }

    public function refreshToken(
        string $appId,
        string $clientSecret,
        string $refreshToken
    ): array {
        $payloadArray = [
            'grant_type' => 'refresh_token',
            'client_id' => trim($appId),
            'client_secret' => trim($clientSecret),
            'refresh_token' => trim($refreshToken),
        ];

        $result = $this->postOAuthToken($payloadArray, 'application/x-www-form-urlencoded');
        if ($result['status'] >= 200 && $result['status'] < 300) {
            return $result;
        }

        return $this->postOAuthToken($payloadArray, 'application/json');
    }

    private function postOAuthToken(array $payloadArray, string $contentType): array
    {
        $url = self::API_BASE . '/oauth/token';

        if ($contentType === 'application/json') {
            $payload = json_encode($payloadArray, JSON_THROW_ON_ERROR);
            $headers = ['Content-Type: application/json', 'Accept: application/json'];
        } else {
            $payload = http_build_query($payloadArray);
            $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->requestLogRepository->register(
                'POST',
                '/oauth/token',
                $httpCode > 0 ? $httpCode : null,
                $payload,
                null,
                $error
            );
            throw new \RuntimeException('Erro cURL ao renovar token: ' . $error);
        }

        $this->requestLogRepository->register(
            'POST',
            '/oauth/token',
            $httpCode,
            $payload,
            $response
        );

        return [
            'status' => $httpCode,
            'body' => json_decode($response, true),
            'raw' => $response,
        ];
    }

    private function request(string $method, string $path, string $accessToken, ?array $payload = null): array
    {
        $url = self::API_BASE . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $payloadJson = null;
        if ($payload !== null) {
            $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->requestLogRepository->register(
                $method,
                $path,
                $httpCode > 0 ? $httpCode : null,
                $payloadJson,
                null,
                $error
            );
            throw new \RuntimeException('Erro cURL na comunicação com Mercado Livre: ' . $error);
        }

        $this->requestLogRepository->register(
            $method,
            $path,
            $httpCode,
            $payloadJson,
            $response
        );

        return [
            'status' => $httpCode,
            'body' => json_decode($response, true),
            'raw' => $response,
        ];
    }
}
