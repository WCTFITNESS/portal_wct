<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MercadoPagoSettingsRepository;

class MercadoPagoPaymentService
{
    public function __construct(
        private MercadoPagoSettingsRepository $settingsRepository,
        private MercadoPagoClient $client
    ) {
    }

    /**
     * GET /v1/payments/{id}
     *
     * @return array{status: int, body: mixed, raw: string}
     */
    public function getPaymentById(string $paymentId): array
    {
        $token = $this->requireToken();
        $paymentId = trim($paymentId);
        if ($paymentId === '' || !ctype_digit($paymentId)) {
            throw new \InvalidArgumentException('ID do pagamento inválido. Use apenas números.');
        }

        return $this->client->get('/v1/payments/' . rawurlencode($paymentId), $token);
    }

    /**
     * Usa o valor da coluna D para localizar um pagamento e retornar order.id.
     *
     * @return array{order_id: ?string, payment_id: ?string, trace: list<array{path: string, status: int, body: mixed}>}
     */
    public function findOrderIdByOperationValue(string $operationValue): array
    {
        $token = $this->requireToken();
        $value = $this->normalizeOperationValue($operationValue);
        if ($value === '') {
            return ['order_id' => null, 'payment_id' => null, 'trace' => []];
        }

        $trace = [];

        if (ctype_digit($value)) {
            $path = '/v1/payments/' . rawurlencode($value);
            $paymentResult = $this->client->get($path, $token);
            $trace[] = ['path' => $path, 'status' => (int) ($paymentResult['status'] ?? 0), 'body' => $paymentResult['body'] ?? null];
            if (($paymentResult['status'] ?? 0) >= 200 && ($paymentResult['status'] ?? 0) < 300 && is_array($paymentResult['body'] ?? null)) {
                $orderId = $this->extractOrderIdFromPaymentBody($paymentResult['body']);
                if ($orderId !== null) {
                    return ['order_id' => $orderId, 'payment_id' => (string) ($paymentResult['body']['id'] ?? $value), 'trace' => $trace];
                }
            }
        }

        $searchResult = $this->searchPayments([
            'external_reference' => $value,
            'limit' => 50,
            'offset' => 0,
        ]);
        $trace[] = ['path' => '/v1/payments/search?external_reference=' . rawurlencode($value), 'status' => (int) ($searchResult['status'] ?? 0), 'body' => $searchResult['body'] ?? null];

        if (($searchResult['status'] ?? 0) < 200 || ($searchResult['status'] ?? 0) >= 300) {
            return ['order_id' => null, 'payment_id' => null, 'trace' => $trace];
        }

        $results = $searchResult['body']['results'] ?? [];
        if (!is_array($results)) {
            return ['order_id' => null, 'payment_id' => null, 'trace' => $trace];
        }

        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }
            $paymentId = (string) ($item['id'] ?? '');
            if ($paymentId !== '' && $paymentId === $value) {
                $orderId = $this->extractOrderIdFromPaymentBody($item);
                if ($orderId !== null) {
                    return ['order_id' => $orderId, 'payment_id' => $paymentId, 'trace' => $trace];
                }
            }
        }

        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }
            $orderId = $this->extractOrderIdFromPaymentBody($item);
            if ($orderId !== null) {
                return ['order_id' => $orderId, 'payment_id' => (string) ($item['id'] ?? ''), 'trace' => $trace];
            }
        }

        return ['order_id' => null, 'payment_id' => null, 'trace' => $trace];
    }

    /**
     * Resolve em lote os valores da coluna D, com chamadas paralelas para ganhar performance.
     *
     * @param list<string> $operationValues
     * @return array{
     *   matches: array<string, array{order_id: string, payment_id: string, status: string}>,
     *   api_calls: int
     * }
     */
    public function findOrderIdsByOperationValues(array $operationValues): array
    {
        $token = $this->requireToken();
        $values = [];
        foreach ($operationValues as $v) {
            $vv = $this->normalizeOperationValue((string) $v);
            if ($vv !== '') {
                // Evita cast automatico para int em chaves numericas do PHP.
                $values['v:' . $vv] = $vv;
            }
        }

        $uniqueValues = array_values($values);
        $matches = [];
        $apiCalls = 0;
        $chunkSize = $this->parallelPaymentLookupChunkSize();

        $numeric = [];
        $textual = [];
        foreach ($uniqueValues as $value) {
            if (ctype_digit($value)) {
                $numeric[] = $value;
            } else {
                $textual[] = $value;
            }
        }

        // Lote 1: pagamentos por ID (mais rapido e preciso)
        foreach (array_chunk($numeric, $chunkSize) as $chunk) {
            $paths = [];
            foreach ($chunk as $value) {
                $value = (string) $value;
                $paths[] = '/v1/payments/' . rawurlencode($value);
            }
            $responses = $this->client->getMany($paths, $token);
            $apiCalls += count($paths);

            foreach ($chunk as $value) {
                $value = (string) $value;
                $path = '/v1/payments/' . rawurlencode($value);
                $resp = $responses[$path] ?? null;
                if (!$resp || ($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300 || !is_array($resp['body'] ?? null)) {
                    continue;
                }
                $orderId = $this->extractOrderIdFromPaymentBody($resp['body']);
                if ($orderId !== null) {
                    $matches[$value] = [
                        'order_id' => $orderId,
                        'payment_id' => (string) ($resp['body']['id'] ?? $value),
                        'status' => 'Encontrado por payment.id',
                    ];
                } else {
                    $externalReference = $this->extractExternalReferenceFromPaymentBody($resp['body']);
                    if ($externalReference !== null) {
                        $matches[$value] = [
                            'order_id' => $externalReference,
                            'payment_id' => (string) ($resp['body']['id'] ?? $value),
                            'status' => 'Order nao encontrado, usado external_reference',
                        ];
                    }
                }
            }
        }

        // Lote 2: fallback por external_reference para o que nao foi encontrado
        $pending = [];
        foreach ($uniqueValues as $value) {
            if (!isset($matches[$value])) {
                $pending[] = $value;
            }
        }

        foreach (array_chunk($pending, $chunkSize) as $chunk) {
            $paths = [];
            foreach ($chunk as $value) {
                $paths[] = $this->buildSearchPathByExternalReference((string) $value);
            }
            $responses = $this->client->getMany($paths, $token);
            $apiCalls += count($paths);

            foreach ($chunk as $value) {
                $value = (string) $value;
                $path = $this->buildSearchPathByExternalReference($value);
                $resp = $responses[$path] ?? null;
                if (!$resp || ($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
                    $matches[$value] = ['order_id' => '', 'payment_id' => '', 'status' => 'Nao encontrado'];
                    continue;
                }

                $results = $resp['body']['results'] ?? [];
                if (!is_array($results)) {
                    $matches[$value] = ['order_id' => '', 'payment_id' => '', 'status' => 'Nao encontrado'];
                    continue;
                }

                $resolved = null;
                foreach ($results as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $paymentId = (string) ($item['id'] ?? '');
                    if ($paymentId !== '' && $paymentId === $value) {
                        $orderId = $this->extractOrderIdFromPaymentBody($item);
                        if ($orderId !== null) {
                            $resolved = ['order_id' => $orderId, 'payment_id' => $paymentId, 'status' => 'Encontrado por search/external_reference'];
                            break;
                        }
                        $externalReference = $this->extractExternalReferenceFromPaymentBody($item);
                        if ($externalReference !== null) {
                            $resolved = [
                                'order_id' => $externalReference,
                                'payment_id' => $paymentId,
                                'status' => 'Order nao encontrado, usado external_reference',
                            ];
                            break;
                        }
                    }
                }
                if ($resolved === null) {
                    foreach ($results as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $orderId = $this->extractOrderIdFromPaymentBody($item);
                        if ($orderId !== null) {
                            $resolved = [
                                'order_id' => $orderId,
                                'payment_id' => (string) ($item['id'] ?? ''),
                                'status' => 'Encontrado por search/external_reference',
                            ];
                            break;
                        }
                        $externalReference = $this->extractExternalReferenceFromPaymentBody($item);
                        if ($externalReference !== null) {
                            $resolved = [
                                'order_id' => $externalReference,
                                'payment_id' => (string) ($item['id'] ?? ''),
                                'status' => 'Order nao encontrado, usado external_reference',
                            ];
                            break;
                        }
                    }
                }

                $matches[$value] = $resolved ?? ['order_id' => '', 'payment_id' => '', 'status' => 'Nao encontrado'];
            }
        }

        return ['matches' => $matches, 'api_calls' => $apiCalls];
    }

    /**
     * GET /v1/payments/search — documentação exige intervalo de datas (range + begin_date + end_date).
     *
     * @return array{status: int, body: mixed, raw: string}
     */
    public function searchPayments(array $options = []): array
    {
        $token = $this->requireToken();

        $sort = trim((string) ($options['sort'] ?? 'date_created'));
        if ($sort === '') {
            $sort = 'date_created';
        }

        $criteria = trim((string) ($options['criteria'] ?? 'desc'));
        if ($criteria !== 'asc' && $criteria !== 'desc') {
            $criteria = 'desc';
        }

        $range = trim((string) ($options['range'] ?? 'date_created'));
        if ($range === '') {
            $range = 'date_created';
        }

        $beginDate = trim((string) ($options['begin_date'] ?? 'NOW-90DAYS'));
        if ($beginDate === '') {
            $beginDate = 'NOW-90DAYS';
        }

        $endDate = trim((string) ($options['end_date'] ?? 'NOW'));
        if ($endDate === '') {
            $endDate = 'NOW';
        }

        $limit = (int) ($options['limit'] ?? 30);
        $limit = max(1, min(50, $limit));

        $offset = (int) ($options['offset'] ?? 0);
        $offset = max(0, min(10000, $offset));

        $params = [
            'sort' => $sort,
            'criteria' => $criteria,
            'range' => $range,
            'begin_date' => $beginDate,
            'end_date' => $endDate,
            'limit' => $limit,
            'offset' => $offset,
        ];

        $externalReference = trim((string) ($options['external_reference'] ?? ''));
        if ($externalReference !== '') {
            $params['external_reference'] = $externalReference;
        }

        $status = trim((string) ($options['status'] ?? ''));
        if ($status !== '') {
            $params['status'] = $status;
        }

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $this->client->get('/v1/payments/search?' . $query, $token);
    }

    /**
     * Consulta avulsa no relatorio de vendas (release report).
     *
     * @return array{items: list<array>, api_calls: int, message: string, responses: list<array{path: string, status: int, body: mixed}>, paging: array{page:int,page_size:int,total:int,total_pages:int}}
     */
    public function searchSalesReportStandalone(string $mode, string $value, ?string $dateFrom, ?string $dateTo, int $page = 1, int $pageSize = 50): array
    {
        $token = $this->requireToken();
        $mode = trim($mode);
        $value = $this->normalizeOperationValue($value);
        $page = max(1, $page);
        $pageSize = max(1, min(50, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $responses = [];
        $apiCalls = 0;

        $params = [
            'limit' => $pageSize,
            'offset' => $offset,
            'begin_date' => $this->normalizeDateBoundary($dateFrom, false),
            'end_date' => $this->normalizeDateBoundary($dateTo, true),
        ];

        $path = '/v1/account/release_report/search?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $result = $this->client->get($path, $token);
        $apiCalls++;
        $responses[] = ['path' => '/v1/account/release_report/search', 'status' => (int) ($result['status'] ?? 0), 'body' => $result['body'] ?? null];

        $items = $this->extractReportItems($result);
        if ($mode !== 'data' && $value !== '') {
            $items = array_values(array_filter($items, function (array $item) use ($mode, $value): bool {
                $id = trim((string) ($item['id'] ?? ''));
                $fileName = trim((string) ($item['file_name'] ?? ''));
                $metadata = trim((string) ($item['metadata'] ?? ''));
                $haystack = mb_strtolower($id . ' ' . $fileName . ' ' . $metadata);
                $needle = mb_strtolower($value);

                if ($mode === 'numero_movimento' && ctype_digit($value)) {
                    return $id === $value || str_contains($haystack, $needle);
                }

                // operacao_relacionada e numero_pedido aplicam filtro textual no retorno da linha
                return str_contains($haystack, $needle);
            }));
        }

        $paging = $this->extractPaging($result, $page, $pageSize);
        $message = 'Consulta no relatorio de vendas concluida.';

        return ['items' => $items, 'api_calls' => $apiCalls, 'message' => $message, 'responses' => $responses, 'paging' => $paging];
    }

    /**
     * Busca avulsa para tela Repasse MP.
     *
     * @return array{items: list<array>, api_calls: int, message: string, responses: list<array{path: string, status: int, body: mixed}>}
     */
    public function searchStandalone(string $mode, string $value, ?string $dateFrom, ?string $dateTo, int $page = 1, int $pageSize = 50): array
    {
        $mode = trim($mode);
        $value = $this->normalizeOperationValue($value);
        $apiCalls = 0;
        $responses = [];
        $page = max(1, $page);
        $pageSize = max(1, min(50, $pageSize));
        $offset = ($page - 1) * $pageSize;

        if ($mode === 'data') {
            $from = $this->normalizeDateBoundary($dateFrom, false);
            $to = $this->normalizeDateBoundary($dateTo, true);
            $result = $this->searchPayments([
                'begin_date' => $from,
                'end_date' => $to,
                'limit' => $pageSize,
                'offset' => $offset,
            ]);
            $apiCalls++;
            $responses[] = ['path' => '/v1/payments/search', 'status' => (int) ($result['status'] ?? 0), 'body' => $result['body'] ?? null];
            $items = $this->extractPaymentItems($result);
            $paging = $this->extractPaging($result, $page, $pageSize);

            return ['items' => $items, 'api_calls' => $apiCalls, 'message' => 'Busca por data concluida.', 'responses' => $responses, 'paging' => $paging];
        }

        if ($value === '') {
            throw new \InvalidArgumentException('Informe um valor para a busca avulsa.');
        }

        if ($mode === 'numero_pedido') {
            $items = $this->findPaymentsByOrderId($value, $dateFrom, $dateTo, $apiCalls, $responses);

            $paging = [
                'page' => 1,
                'page_size' => count($items),
                'total' => count($items),
                'total_pages' => 1,
            ];

            return ['items' => $items, 'api_calls' => $apiCalls, 'message' => 'Busca por numero de pedido concluida.', 'responses' => $responses, 'paging' => $paging];
        }

        // operacao_relacionada e numero_movimento
        $items = [];
        if (ctype_digit($value)) {
            $byId = $this->getPaymentById($value);
            $apiCalls++;
            $responses[] = ['path' => '/v1/payments/' . rawurlencode($value), 'status' => (int) ($byId['status'] ?? 0), 'body' => $byId['body'] ?? null];
            if (($byId['status'] ?? 0) >= 200 && ($byId['status'] ?? 0) < 300 && is_array($byId['body'] ?? null)) {
                $items[] = $byId['body'];
            }
        }

        if ($items === []) {
            $search = $this->searchPayments([
                'external_reference' => $value,
                'begin_date' => $this->normalizeDateBoundary($dateFrom, false),
                'end_date' => $this->normalizeDateBoundary($dateTo, true),
                'limit' => $pageSize,
                'offset' => $offset,
            ]);
            $apiCalls++;
            $responses[] = ['path' => '/v1/payments/search', 'status' => (int) ($search['status'] ?? 0), 'body' => $search['body'] ?? null];
            $items = $this->extractPaymentItems($search);
            $paging = $this->extractPaging($search, $page, $pageSize);
        } else {
            $paging = [
                'page' => 1,
                'page_size' => count($items),
                'total' => count($items),
                'total_pages' => 1,
            ];
        }

        return ['items' => $items, 'api_calls' => $apiCalls, 'message' => 'Busca avulsa concluida.', 'responses' => $responses, 'paging' => $paging];
    }

    private function parallelPaymentLookupChunkSize(): int
    {
        $v = (int) getenv('MERCADOPAGO_PARALLEL_CHUNK');
        if ($v <= 0) {
            return 48;
        }

        return max(10, min(80, $v));
    }

    private function requireToken(): string
    {
        $token = $this->settingsRepository->getAccessToken();
        if ($token === '') {
            throw new \RuntimeException('Configure o access token do Mercado Pago na mesma tela (Repasse MP).');
        }

        return $token;
    }

    private function extractOrderIdFromPaymentBody(array $paymentBody): ?string
    {
        $order = $paymentBody['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }

        $orderId = trim((string) ($order['id'] ?? ''));

        return $orderId !== '' ? $orderId : null;
    }

    private function extractExternalReferenceFromPaymentBody(array $paymentBody): ?string
    {
        $externalReference = trim((string) ($paymentBody['external_reference'] ?? ''));

        return $externalReference !== '' ? $externalReference : null;
    }

    private function buildSearchPathByExternalReference(string $externalReference): string
    {
        $params = [
            'sort' => 'date_created',
            'criteria' => 'desc',
            'range' => 'date_created',
            'begin_date' => 'NOW-90DAYS',
            'end_date' => 'NOW',
            'limit' => 50,
            'offset' => 0,
            'external_reference' => $externalReference,
        ];

        return '/v1/payments/search?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param int $apiCalls contador acumulado de chamadas
     * @param list<array{path: string, status: int, body: mixed}> $responses
     * @return list<array>
     */
    private function findPaymentsByOrderId(string $orderId, ?string $dateFrom, ?string $dateTo, int &$apiCalls, array &$responses): array
    {
        $from = $this->normalizeDateBoundary($dateFrom, false);
        $to = $this->normalizeDateBoundary($dateTo, true);
        $items = [];
        $maxPages = 6; // ate 300 itens

        for ($page = 0; $page < $maxPages; $page++) {
            $offset = $page * 50;
            $result = $this->searchPayments([
                'begin_date' => $from,
                'end_date' => $to,
                'limit' => 50,
                'offset' => $offset,
            ]);
            $apiCalls++;
            $responses[] = ['path' => '/v1/payments/search?offset=' . $offset, 'status' => (int) ($result['status'] ?? 0), 'body' => $result['body'] ?? null];
            $pageItems = $this->extractPaymentItems($result);
            if ($pageItems === []) {
                break;
            }

            foreach ($pageItems as $payment) {
                $pid = trim((string) (($payment['order']['id'] ?? '')));
                if ($pid === $orderId) {
                    $items[] = $payment;
                }
            }
        }

        return $items;
    }

    /**
     * @return list<array>
     */
    private function extractPaymentItems(array $result): array
    {
        if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
            return [];
        }

        $body = $result['body'] ?? null;
        if (!is_array($body)) {
            return [];
        }

        if (isset($body['results']) && is_array($body['results'])) {
            $out = [];
            foreach ($body['results'] as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }

            return $out;
        }

        if (isset($body['id'])) {
            return [$body];
        }

        return [];
    }

    /**
     * @return list<array>
     */
    private function extractReportItems(array $result): array
    {
        if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
            return [];
        }

        $body = $result['body'] ?? null;
        if (!is_array($body)) {
            return [];
        }

        $rows = $body['results'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private function normalizeDateBoundary(?string $date, bool $endOfDay): string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return $endOfDay ? 'NOW' : 'NOW-90DAYS';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $endOfDay ? $date . 'T23:59:59.000-03:00' : $date . 'T00:00:00.000-03:00';
        }

        return $date;
    }

    private function extractPaging(array $result, int $page, int $pageSize): array
    {
        $body = $result['body'] ?? null;
        $paging = is_array($body) ? ($body['paging'] ?? null) : null;
        $total = 0;
        if (is_array($paging) && isset($paging['total'])) {
            $total = (int) $paging['total'];
        }

        $totalPages = $total > 0 ? (int) ceil($total / $pageSize) : $page;
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    private function normalizeOperationValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace(["\xc2\xa0", ' '], '', $value);

        if (preg_match('/^\d+\.0+$/', $value) === 1) {
            $value = preg_replace('/\.0+$/', '', $value) ?? $value;
        }

        if (preg_match('/^\d+(?:\.\d+)?e[+\-]?\d+$/i', $value) === 1) {
            $floatValue = (float) $value;
            if (is_finite($floatValue)) {
                $value = sprintf('%.0f', $floatValue);
            }
        }

        return trim($value);
    }
}
