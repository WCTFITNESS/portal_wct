<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RequestLogRepository;

class MercadoPagoClient
{
    private const API_BASE = 'https://api.mercadopago.com';

    public function __construct(private RequestLogRepository $requestLogRepository)
    {
    }

    public function get(string $pathWithQuery, string $accessToken): array
    {
        if ($pathWithQuery === '' || $pathWithQuery[0] !== '/') {
            throw new \InvalidArgumentException('Path deve começar com /.');
        }

        $url = self::API_BASE . $pathWithQuery;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $logPath = 'MP ' . $pathWithQuery;
        if (strlen($logPath) > 250) {
            $logPath = substr($logPath, 0, 247) . '...';
        }

        if ($response === false) {
            $this->requestLogRepository->register('GET', $logPath, $httpCode > 0 ? $httpCode : null, null, null, $error);
            throw new \RuntimeException('Erro cURL na comunicação com Mercado Pago: ' . $error);
        }

        $this->requestLogRepository->register('GET', $logPath, $httpCode, null, $response);

        return [
            'status' => $httpCode,
            'body' => json_decode($response, true),
            'raw' => $response,
        ];
    }

    /**
     * @param list<string> $pathsWithQuery
     * @return array<string, array{status: int, body: mixed, raw: string}>
     */
    public function getMany(array $pathsWithQuery, string $accessToken): array
    {
        $results = [];
        if ($pathsWithQuery === []) {
            return $results;
        }

        $mh = curl_multi_init();
        $handles = [];

        foreach ($pathsWithQuery as $pathWithQuery) {
            if ($pathWithQuery === '' || $pathWithQuery[0] !== '/') {
                continue;
            }

            $url = self::API_BASE . $pathWithQuery;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Accept: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(string) spl_object_id($ch)] = ['handle' => $ch, 'path' => $pathWithQuery];
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $item) {
            $ch = $item['handle'];
            $path = $item['path'];
            $response = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            $logPath = 'MP ' . $path;
            if (strlen($logPath) > 250) {
                $logPath = substr($logPath, 0, 247) . '...';
            }

            if ($error !== '') {
                $this->requestLogRepository->register('GET', $logPath, $httpCode > 0 ? $httpCode : null, null, null, $error);
                $results[$path] = ['status' => $httpCode, 'body' => null, 'raw' => ''];
            } else {
                $raw = is_string($response) ? $response : '';
                $this->requestLogRepository->register('GET', $logPath, $httpCode, null, $raw);
                $results[$path] = [
                    'status' => $httpCode,
                    'body' => json_decode($raw, true),
                    'raw' => $raw,
                ];
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        return $results;
    }
}
