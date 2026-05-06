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

    public function getProducts(string $startDate, string $endDate, string $search = '', int $take = 50, int $skip = 0): array
    {
        $payload = [
            'requiresCounts' => true,
            'aggregates' => [['type' => 'sum', 'field' => 'TotalVendidoItem']],
            'skip' => max(0, $skip),
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

        return [
            'items' => is_array($json['result'] ?? null) ? $json['result'] : [],
            'count' => (int) ($json['count'] ?? 0),
        ];
    }

    public function getComparisonData(array $years): array
    {
        $months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        $fixed = [
            '2023' => [5191125.26,4092022.51,5227727.96,4198716.11,4295843.08,4659940.54,6149869.76,6342991.11,5872698.72,7168073.52,8307210.20,5825889.92],
            '2024' => [8132559.37,7510752.70,4526078.71,6558938.81,8485639.29,9064520.43,7652913.90,5475485.75,12043535.43,11708278.41,11002977.56,8471211.17],
            '2025' => [12878026.21,10859866.95,12074979.41,11772181.76,13073308.07,11624038.21,13537082.08,13578998.72,15866370.75,17944935.00,24328467.94,14098352.24],
        ];

        $data = [];
        foreach ($years as $yearRaw) {
            $year = (string) $yearRaw;
            if (isset($fixed[$year])) {
                $data[$year] = $fixed[$year];
                continue;
            }
            $yr = (int) $year;
            if ($yr < 2000 || $yr > 2100) {
                continue;
            }
            $data[$year] = $this->fetchYearRevenueFromMetabase($yr);
        }

        return ['months' => $months, 'series' => $data];
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

    /**
     * @return list<float>
     */
    private function fetchYearRevenueFromMetabase(int $year): array
    {
        $values = array_fill(0, 12, 0.0);
        $current = new \DateTimeImmutable('now');
        $maxMonth = $year === (int) $current->format('Y') ? (int) $current->format('n') : 12;
        for ($m = 1; $m <= $maxMonth; $m++) {
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $end = $start->modify('last day of this month');
            $range = $start->format('Y-m-d') . '~' . $end->format('Y-m-d');
            $url = "https://lexos.metabaseapp.com/api/embed/dashboard/eyJhbGciOiJodHRwOi8vd3d3LnczLm9yZy8yMDAxLzA0L3htbGRzaWctbW9yZSNobWFjLXNoYTI1NiIsInR5cCI6IkpXVCJ9.eyJyZXNvdXJjZSI6eyJkYXNoYm9hcmQiOjMzMX0sInBhcmFtcyI6eyJ0ZW5hbnRpZCI6ODU2Nn19.KzSD6VAtepVnwJvcthpCtHpSGwj5rSC4G30fQjFBn6E/dashcard/875/card/779?parameters=%7B%22filtro_de_data%22%3A%22{$range}%22%2C%22integra%25C3%25A7%25C3%25A3o%22%3Anull%7D";
            $json = $this->httpGetJson($url);
            $values[$m - 1] = (float) ($json['data']['rows'][0][0] ?? 0);
        }

        return $values;
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

