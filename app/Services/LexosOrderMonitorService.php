<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use RuntimeException;

class LexosOrderMonitorService
{
    public function __construct(private SettingsRepository $settingsRepository)
    {
    }

    public function findOrderTimeline(string $orderQuery, string $startDate, string $endDate, int $take = 100): array
    {
        $orderQuery = trim($orderQuery);
        if ($orderQuery === '') {
            throw new RuntimeException('Informe um número de pedido para monitorar.');
        }

        $take = max(1, min(500, $take));
        $url = "https://app-hub-webapi.lexos.com.br/api/Pedido/DataSource?lojaId=-1&initialDate={$startDate}T00:00:00&finalDate={$endDate}T23:59:59";
        $payload = [
            'requiresCounts' => false,
            'skip' => 0,
            'take' => $take,
            'search' => [[
                'fields' => ['Search', 'Pedido', 'NumeroPedido', 'Codigo', 'CodigoPedido', 'Numero'],
                'operator' => 'contains',
                'key' => $orderQuery,
                'ignoreCase' => true,
            ]],
        ];

        $json = $this->httpPostLexosJson($url, $payload);
        $rows = $this->normalizeDatasourceRows($json);

        $timelines = [];
        foreach ($rows as $row) {
            $orderId = $this->extractOrderIdentifier($row);
            $status = $this->extractStatus($row);
            $eventDate = $this->extractEventDate($row);

            if (!isset($timelines[$orderId])) {
                $timelines[$orderId] = [];
            }

            $timelines[$orderId][] = [
                'status' => $status,
                'date' => $eventDate,
                'action' => $this->recommendedActionForStatus($status),
                'row' => $row,
            ];
        }

        foreach ($timelines as $orderId => $events) {
            usort($events, static function (array $a, array $b): int {
                return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
            });
            $timelines[$orderId] = $events;
        }

        return [
            'query' => $orderQuery,
            'rows' => $rows,
            'timelines' => $timelines,
        ];
    }

    public function monitorPeriod(string $startDate, string $endDate, int $take = 1000): array
    {
        $take = max(1, min(5000, $take));
        $json = $this->fetchPedidoRowsWithFallback($startDate, $endDate, $take);
        $rows = $this->normalizeDatasourceRows($json);

        $timelines = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = $this->extractOrderIdentifier($row);
            $status = $this->extractStatus($row);
            $eventDate = $this->extractEventDate($row);

            $timelines[$orderId][] = [
                'status' => $status,
                'date' => $eventDate,
                'action' => $this->recommendedActionForStatus($status),
                'row' => $row,
            ];
        }

        foreach ($timelines as $orderId => $events) {
            usort($events, static function (array $a, array $b): int {
                return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
            });
            $timelines[$orderId] = $events;
        }

        $summary = [
            'aberto' => 0,
            'faturado' => 0,
            'atraso' => 0,
            'enviado' => 0,
            'entregue' => 0,
            'outros' => 0,
            'total_pedidos' => count($timelines),
        ];

        $orders = [];
        foreach ($timelines as $orderId => $events) {
            $last = $events[count($events) - 1] ?? null;
            if (!$last) {
                continue;
            }

            $status = (string) ($last['status'] ?? 'Status não identificado');
            $category = $this->categorizeStatus($status);
            if (!isset($summary[$category])) {
                $summary[$category] = 0;
            }
            $summary[$category]++;

            $orders[] = [
                'order_id' => $orderId,
                'status' => $status,
                'category' => $category,
                'date' => (string) ($last['date'] ?? ''),
                'action' => (string) ($last['action'] ?? ''),
                'events_count' => count($events),
            ];
        }

        return [
            'rows' => $rows,
            'timelines' => $timelines,
            'orders' => $orders,
            'summary' => $summary,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    public function getRecentOrderExample(string $startDate, string $endDate): ?string
    {
        $url = "https://app-hub-webapi.lexos.com.br/api/Pedido/DataSource?lojaId=-1&initialDate={$startDate}T00:00:00&finalDate={$endDate}T23:59:59";
        $payload = [
            'requiresCounts' => false,
            'skip' => 0,
            'take' => 1,
            'sorted' => [[
                'name' => 'DataPedido',
                'direction' => 'descending',
            ]],
        ];

        $json = $this->httpPostLexosJson($url, $payload);
        $rows = $this->normalizeDatasourceRows($json);
        if ($rows === []) {
            return null;
        }

        return $this->extractOrderIdentifier((array) $rows[0]);
    }

    private function extractOrderIdentifier(array $row): string
    {
        $candidates = ['Pedido', 'NumeroPedido', 'CodigoPedido', 'Codigo', 'Numero', 'IdPedido', 'OrderId'];
        foreach ($candidates as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'sem_identificador';
    }

    private function extractStatus(array $row): string
    {
        $candidates = ['Status', 'StatusPedido', 'Situacao', 'SituacaoPedido', 'Estado', 'StatusIntegracao'];
        foreach ($candidates as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Status não identificado';
    }

    private function extractEventDate(array $row): string
    {
        $candidates = ['DataAtualizacao', 'DataAlteracao', 'DataStatus', 'DataPedido', 'DataCriacao', 'Data'];
        foreach ($candidates as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function recommendedActionForStatus(string $status): string
    {
        $s = mb_strtolower($status);

        if (str_contains($s, 'erro') || str_contains($s, 'rejeit')) {
            return 'Reprocessar pedido na Lexos e validar credenciais da integração.';
        }
        if (str_contains($s, 'cancel')) {
            return 'Validar motivo do cancelamento e alinhar tratativa com o comercial.';
        }
        if (str_contains($s, 'aprov')) {
            return 'Pedido aprovado; seguir para separação e faturamento.';
        }
        if (str_contains($s, 'fatur') || str_contains($s, 'nota')) {
            return 'Pedido faturado; acompanhar postagem/coleta da transportadora.';
        }
        if (str_contains($s, 'envia') || str_contains($s, 'post')) {
            return 'Pedido enviado; acompanhar rastreio até entrega.';
        }
        if (str_contains($s, 'entreg') || str_contains($s, 'conclu')) {
            return 'Pedido finalizado; nenhuma ação operacional pendente.';
        }

        return 'Status não mapeado; validar manualmente no painel da Lexos.';
    }

    private function categorizeStatus(string $status): string
    {
        $s = mb_strtolower($status);

        if (str_contains($s, 'atras')) {
            return 'atraso';
        }
        if (str_contains($s, 'entreg') || str_contains($s, 'conclu')) {
            return 'entregue';
        }
        if (str_contains($s, 'envia') || str_contains($s, 'post') || str_contains($s, 'transit')) {
            return 'enviado';
        }
        if (str_contains($s, 'fatur') || str_contains($s, 'nota')) {
            return 'faturado';
        }
        if (
            str_contains($s, 'abert')
            || str_contains($s, 'pend')
            || str_contains($s, 'aguard')
            || str_contains($s, 'separ')
            || str_contains($s, 'aprov')
        ) {
            return 'aberto';
        }

        return 'outros';
    }

    private function normalizeDatasourceRows(mixed $json): array
    {
        if (!is_array($json)) {
            return [];
        }
        if ($json === []) {
            return [];
        }
        if (array_keys($json) === range(0, count($json) - 1)) {
            return $json;
        }
        if (isset($json['result']) && is_array($json['result'])) {
            return array_keys($json['result']) === range(0, count($json['result']) - 1) ? $json['result'] : [];
        }
        if (isset($json['data']) && is_array($json['data'])) {
            return array_keys($json['data']) === range(0, count($json['data']) - 1) ? $json['data'] : [];
        }

        return [];
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

            $extra = '';
            if (is_string($raw) && $raw !== '') {
                $extra = ' Resposta: ' . substr($raw, 0, 400);
            }
            $lastError = new RuntimeException('Falha consulta Lexos WebAPI. HTTP: ' . $status . ' Tentativa: ' . $variant['label'] . ($err !== '' ? ' ' . $err : '') . $extra);
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

    private function fetchPedidoRowsWithFallback(string $startDate, string $endDate, int $take): array
    {
        $attempts = [
            [
                'url' => "https://app-hub-webapi.lexos.com.br/api/Pedido/DataSource?lojaId=-1&initialDate={$startDate}T00:00:00&finalDate={$endDate}T23:59:59",
                'payload' => [
                    'requiresCounts' => false,
                    'skip' => 0,
                    'take' => $take,
                ],
            ],
            [
                'url' => 'https://app-hub-webapi.lexos.com.br/api/Pedido/DataSource?lojaId=-1',
                'payload' => [
                    'requiresCounts' => false,
                    'skip' => 0,
                    'take' => $take,
                    'where' => [[
                        'isComplex' => true,
                        'ignoreCase' => false,
                        'condition' => 'and',
                        'predicates' => [
                            ['isComplex' => false, 'field' => 'DataPedido', 'operator' => 'greaterthanorequal', 'value' => "{$startDate}T00:00:00"],
                            ['isComplex' => false, 'field' => 'DataPedido', 'operator' => 'lessthanorequal', 'value' => "{$endDate}T23:59:59"],
                        ],
                    ]],
                ],
            ],
        ];

        $lastError = null;
        foreach ($attempts as $attempt) {
            try {
                return $this->httpPostLexosJson($attempt['url'], $attempt['payload']);
            } catch (RuntimeException $exception) {
                $lastError = $exception;
            }
        }

        if ($lastError !== null) {
            throw $lastError;
        }

        throw new RuntimeException('Falha ao consultar pedidos na Lexos.');
    }
}
