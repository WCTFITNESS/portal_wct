<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;

class LexosDashboardService
{
    public function __construct(private SettingsRepository $settingsRepository)
    {
    }

    public function getDashboardMetrics(string $startDate, string $endDate): array
    {
        $range = $startDate . '~' . $endDate;
        $urls = [
            'faturamento' => "https://lexos.metabaseapp.com/api/embed/dashboard/eyJhbGciOiJodHRwOi8vd3d3LnczLm9yZy8yMDAxLzA0L3htbGRzaWctbW9yZSNobWFjLXNoYTI1NiIsInR5cCI6IkpXVCJ9.eyJyZXNvdXJjZSI6eyJkYXNoYm9hcmQiOjMzMX0sInBhcmFtcyI6eyJ0ZW5hbnRpZCI6ODU2Nn19.KzSD6VAtepVnwJvcthpCtHpSGwj5rSC4G30fQjFBn6E/dashcard/875/card/779?parameters=%7B%22filtro_de_data%22%3A%22{$range}%22%2C%22integra%25C3%25A7%25C3%25A3o%22%3Anull%7D",
            'pedidos' => "https://lexos.metabaseapp.com/api/embed/dashboard/eyJhbGciOiJodHRwOi8vd3d3LnczLm9yZy8yMDAxLzA0L3htbGRzaWctbW9yZSNobWFjLXNoYTI1NiIsInR5cCI6IkpXVCJ9.eyJyZXNvdXJjZSI6eyJkYXNoYm9hcmQiOjMzMX0sInBhcmFtcyI6eyJ0ZW5hbnRpZCI6ODU2Nn19.KzSD6VAtepVnwJvcthpCtHpSGwj5rSC4G30fQjFBn6E/dashcard/898/card/781?parameters=%7B%22filtro_de_data%22%3A%22{$range}%22%2C%22integra%25C3%25A7%25C3%25A3o%22%3Anull%7D",
            'ticket' => "https://lexos.metabaseapp.com/api/embed/dashboard/eyJhbGciOiJodHRwOi8vd3d3LnczLm9yZy8yMDAxLzA0L3htbGRzaWctbW9yZSNobWFjLXNoYTI1NiIsInR5cCI6IkpXVCJ9.eyJyZXNvdXJjZSI6eyJkYXNoYm9hcmQiOjMzMX0sInBhcmFtcyI6eyJ0ZW5hbnRpZCI6ODU2Nn19.KzSD6VAtepVnwJvcthpCtHpSGwj5rSC4G30fQjFBn6E/dashcard/866/card/760?parameters=%7B%22filtro_de_data%22%3A%22{$range}%22%2C%22integra%25C3%25A7%25C3%25A3o%22%3Anull%7D",
            'canais' => "https://lexos.metabaseapp.com/api/embed/dashboard/eyJhbGciOiJodHRwOi8vd3d3LnczLm9yZy8yMDAxLzA0L3htbGRzaWctbW9yZSNobWFjLXNoYTI1NiIsInR5cCI6IkpXVCJ9.eyJyZXNvdXJjZSI6eyJkYXNoYm9hcmQiOjMzMX0sInBhcmFtcyI6eyJ0ZW5hbnRpZCI6ODU2Nn19.KzSD6VAtepVnwJvcthpCtHpSGwj5rSC4G30fQjFBn6E/dashcard/870/card/793?parameters=%7B%22filtro_de_data%22%3A%22{$range}%22%2C%22integra%25C3%25A7%25C3%25A3o%22%3Anull%7D",
        ];

        $f = $this->httpGetJson($urls['faturamento']);
        $p = $this->httpGetJson($urls['pedidos']);
        $t = $this->httpGetJson($urls['ticket']);
        $c = $this->httpGetJson($urls['canais']);

        return [
            'faturamento' => (float) ($f['data']['rows'][0][0] ?? 0),
            'pedidos' => (int) ($p['data']['rows'][0][0] ?? 0),
            'ticket_medio' => (float) ($t['data']['rows'][0][0] ?? 0),
            'canais' => is_array($c['data']['rows'] ?? null) ? $c['data']['rows'] : [],
        ];
    }

    public function getProducts(string $startDate, string $endDate, string $search = '', int $take = 50): array
    {
        $payload = [
            'requiresCounts' => true,
            'aggregates' => [['type' => 'sum', 'field' => 'TotalVendidoItem']],
            'skip' => 0,
            'take' => max(1, min(500, $take)),
        ];
        if ($search !== '') {
            $payload['search'] = [[
                'fields' => ['Nome', 'Sku', 'Ean'],
                'operator' => 'contains',
                'key' => $search,
                'ignoreCase' => true,
            ]];
        }

        $url = "https://app-hub-webapi.lexos.com.br/api/RelatorioVendas/DataSourceCurvaAbc?lojaId=-1&initialDate={$startDate}T00:00:00&finalDate={$endDate}T23:59:59";
        $json = $this->httpPostLexosJson($url, $payload);

        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }

    public function getSkuAnalysis(string $sku, string $startDate, string $endDate): array
    {
        $salesPayload = [
            'requiresCounts' => false,
            'where' => [[
                'isComplex' => true,
                'ignoreCase' => false,
                'condition' => 'and',
                'predicates' => [[
                    'isComplex' => true,
                    'ignoreCase' => false,
                    'condition' => 'and',
                    'predicates' => [
                        ['isComplex' => false, 'field' => 'DataAprovacao', 'operator' => 'greaterthanorequal', 'value' => "{$startDate}T00:00:00.000Z", 'ignoreCase' => false],
                        ['isComplex' => false, 'field' => 'DataAprovacao', 'operator' => 'lessthanorequal', 'value' => "{$endDate}T23:59:59.000Z", 'ignoreCase' => false],
                    ],
                ], [
                    'isComplex' => false,
                    'field' => 'Sku',
                    'operator' => 'startswith',
                    'value' => $sku,
                    'ignoreCase' => true,
                    'ignoreAccent' => false,
                ]],
            ]],
            'aggregates' => [['type' => 'sum', 'field' => 'Valor'], ['type' => 'sum', 'field' => 'Qtde']],
            'skip' => 0,
            'take' => 5000,
        ];
        $salesUrl = 'https://app-hub-webapi.lexos.com.br/api/RelatorioVendas/DataSourceLucratividadeItem';
        $sales = $this->httpPostLexosJson($salesUrl, $salesPayload);

        $stockPayload = [
            'requiresCounts' => false,
            'search' => [['fields' => ['Search'], 'operator' => 'contains', 'key' => $sku, 'ignoreCase' => true]],
            'where' => [[
                'isComplex' => true,
                'ignoreCase' => true,
                'ignoreAccent' => false,
                'condition' => 'and',
                'predicates' => [[
                    'isComplex' => false,
                    'field' => 'Sku',
                    'operator' => 'startswith',
                    'value' => $sku,
                    'ignoreCase' => true,
                    'ignoreAccent' => false,
                ]],
            ]],
            'skip' => 0,
            'take' => 1,
        ];
        $stockUrl = 'https://app-hub-webapi.lexos.com.br/api/Produto/DataSource?lojaId=-1';
        $stock = $this->httpPostLexosJson($stockUrl, $stockPayload);

        return [
            'sales' => is_array($sales) ? $sales : [],
            'stock' => is_array($stock) ? $stock : [],
        ];
    }

    private function getLexosToken(): string
    {
        $cfg = $this->settingsRepository->getApiConfig();
        $token = trim((string) ($cfg['lexos_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Token Lexos nao configurado em Configuracao API.');
        }

        return $token;
    }

    private function httpGetJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 40,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Falha consulta Lexos/Metabase. HTTP: ' . $status . ($err !== '' ? ' ' . $err : ''));
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function httpPostLexosJson(string $url, array $payload): array
    {
        $token = $this->getLexosToken();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Falha consulta Lexos WebAPI. HTTP: ' . $status . ($err !== '' ? ' ' . $err : ''));
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

