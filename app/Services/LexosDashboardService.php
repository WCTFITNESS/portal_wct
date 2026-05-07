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
        $norm = $this->normalizeAppHubDatasourceResponse($json);

        return [
            'items' => $norm['result'],
            'count' => (int) ($json['count'] ?? $norm['count'] ?? count($norm['result'])),
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
        $salesRaw = $this->httpPostLexosJson($salesUrl, $salesPayload);

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
        $stockRaw = $this->httpPostLexosJson($stockUrl, $stockPayload);

        return [
            'sales' => $this->normalizeAppHubDatasourceResponse($salesRaw),
            'stock' => $this->normalizeAppHubDatasourceResponse($stockRaw),
        ];
    }

    /**
     * A WebAPI por vezes retorna um array JSON direto [...] (como no plugin) e por vezes { "result": [...] }.
     * O portal sempre trabalha com a forma { "result": rows } para alinhar ao plugin.
     *
     * @return array{result: list<array<string, mixed>>, count: int}
     */
    private function normalizeAppHubDatasourceResponse(mixed $json): array
    {
        if (!is_array($json)) {
            return ['result' => [], 'count' => 0];
        }
        if ($json === []) {
            return ['result' => [], 'count' => 0];
        }
        if ($this->isSequentialList($json)) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $json;

            return ['result' => $rows, 'count' => count($rows)];
        }
        if (isset($json['result']) && is_array($json['result'])) {
            $rows = $json['result'];
            $list = $this->isSequentialList($rows) ? $rows : [];

            return [
                'result' => $list,
                'count' => (int) ($json['count'] ?? count($list)),
            ];
        }
        if (isset($json['data']) && is_array($json['data']) && $this->isSequentialList($json['data'])) {
            $rows = $json['data'];

            return ['result' => $rows, 'count' => count($rows)];
        }

        return ['result' => [], 'count' => 0];
    }

    /**
     * @param array<mixed> $arr
     */
    private function isSequentialList(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) === range(0, count($arr) - 1);
    }

    private function getLexosToken(): string
    {
        $cfg = $this->settingsRepository->getApiConfig();
        $token = trim((string) ($cfg['lexos_token'] ?? ''));
        $token = preg_replace('/^\s*Bearer\s+/i', '', $token) ?? $token;
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
     * Várias requisições GET em paralelo (comparação anual — meses do ano atual no Metabase).
     *
     * @param list<string> $urls
     * @return list<array<string, mixed>>
     */
    private function httpGetJsonParallel(array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        if (!function_exists('curl_multi_init')) {
            $out = [];
            foreach ($urls as $u) {
                $out[] = $this->httpGetJson($u);
            }

            return $out;
        }

        $mh = curl_multi_init();
        if ($mh === false) {
            $out = [];
            foreach ($urls as $u) {
                $out[] = $this->httpGetJson($u);
            }

            return $out;
        }

        $handles = [];
        foreach ($urls as $i => $url) {
            $ch = curl_init($url);
            if ($ch === false) {
                foreach ($handles as $h) {
                    curl_multi_remove_handle($mh, $h);
                    curl_close($h);
                }
                curl_multi_close($mh);
                throw new RuntimeException('Falha ao iniciar requisição Metabase.');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_TIMEOUT => 25,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $running = 0;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0);

        $decodedList = array_fill(0, count($urls), []);
        try {
            foreach ($handles as $i => $ch) {
                $raw = curl_multi_getcontent($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                if ($raw === false || $status < 200 || $status >= 300) {
                    throw new RuntimeException('Falha consulta Lexos/Metabase. HTTP: ' . $status . ($err !== '' ? ' ' . $err : ''));
                }
                $decoded = json_decode((string) $raw, true);
                $decodedList[$i] = is_array($decoded) ? $decoded : [];
            }
        } finally {
            foreach ($handles as $ch) {
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        return $decodedList;
    }

    /**
     * @return list<float>
     */
    private function fetchYearRevenueFromMetabase(int $year): array
    {
        $values = array_fill(0, 12, 0.0);
        $current = new \DateTimeImmutable('now');
        $maxMonth = $year === (int) $current->format('Y') ? (int) $current->format('n') : 12;

        $urls = [];
        for ($m = 1; $m <= $maxMonth; $m++) {
            $start = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m));
            $end = $start->modify('last day of this month');
            $range = $start->format('Y-m-d') . '~' . $end->format('Y-m-d');
            $urls[] = "https://lexos.metabaseapp.com/api/embed/dashboard/eyJhbGciOiJodHRwOi8vd3d3LnczLm9yZy8yMDAxLzA0L3htbGRzaWctbW9yZSNobWFjLXNoYTI1NiIsInR5cCI6IkpXVCJ9.eyJyZXNvdXJjZSI6eyJkYXNoYm9hcmQiOjMzMX0sInBhcmFtcyI6eyJ0ZW5hbnRpZCI6ODU2Nn19.KzSD6VAtepVnwJvcthpCtHpSGwj5rSC4G30fQjFBn6E/dashcard/875/card/779?parameters=%7B%22filtro_de_data%22%3A%22{$range}%22%2C%22integra%25C3%25A7%25C3%25A3o%22%3Anull%7D";
        }

        $responses = $this->httpGetJsonParallel($urls);
        for ($m = 1; $m <= $maxMonth; $m++) {
            $json = $responses[$m - 1] ?? [];
            $values[$m - 1] = (float) ($json['data']['rows'][0][0] ?? 0);
        }

        return $values;
    }

    private function httpPostLexosJson(string $url, array $payload): array
    {
        $token = $this->getLexosToken();
        $integrationKey = $this->getLexosIntegrationKey();
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $baseHeaders = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($integrationKey !== '') {
            $customHeaderName = $this->getLexosIntegrationHeaderName();
            if ($customHeaderName !== '') {
                $baseHeaders[] = $customHeaderName . ': ' . $integrationKey;
            }
            $baseHeaders[] = 'x-api-key: ' . $integrationKey;
            $baseHeaders[] = 'x-integration-key: ' . $integrationKey;
            $baseHeaders[] = 'integration-key: ' . $integrationKey;
            $baseHeaders[] = 'chave-integracao: ' . $integrationKey;
        }

        $authVariants = [
            ['headers' => ['Authorization: Bearer ' . $token], 'label' => 'authorization_bearer'],
            ['headers' => ['Authorization: ' . $token], 'label' => 'authorization_raw'],
            ['headers' => ['Authorization: Token ' . $token], 'label' => 'authorization_token_prefix'],
            ['headers' => ['token: ' . $token], 'label' => 'token_header'],
            ['headers' => ['x-access-token: ' . $token], 'label' => 'x_access_token_header'],
            ['headers' => [], 'label' => 'without_auth_header'],
        ];

        $lastError = null;
        foreach ($authVariants as $variant) {
            [$status, $err, $raw] = $this->executeLexosPost($url, $body, array_merge($baseHeaders, $variant['headers']));
            if ($raw !== false && $status >= 200 && $status < 300) {
                $decoded = json_decode((string) $raw, true);

                return is_array($decoded) ? $decoded : [];
            }

            $lastError = new RuntimeException('Falha consulta Lexos WebAPI. HTTP: ' . $status . ' Tentativa: ' . $variant['label'] . ($err !== '' ? ' ' . $err : ''));
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
     * @param list<string> $headers
     * @return array{int,string,string|false}
     */
    private function executeLexosPost(string $url, string $body, array $headers): array
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
}

