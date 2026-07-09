<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MessageTemplateRepository;
use App\Repositories\SettingsRepository;

class OrderService
{
    public function __construct(
        private TokenService $tokenService,
        private MercadoLivreClient $client,
        private SettingsRepository $settingsRepository,
        private MessageTemplateRepository $templateRepository,
        private MessageService $messageService
    ) {
    }

    public function processCompletedOrders(): int
    {
        $template = $this->templateRepository->getActiveTemplate();
        if (!$template) {
            return 0;
        }

        $orders = $this->listOrders('paid', 50);
        $apiConfig = $this->settingsRepository->getApiConfig();
        $sellerId = (string) ($apiConfig['seller_id'] ?? '');
        $processed = 0;

        foreach ($orders as $order) {
            $orderId = (string) ($order['id'] ?? '');
            $buyerId = (string) ($order['buyer']['id'] ?? '');

            if ($orderId === '' || $buyerId === '') {
                continue;
            }

            $message = str_replace(
                ['{{nome_cliente}}', '{{pedido_id}}'],
                [(string) ($order['buyer']['nickname'] ?? 'Cliente'), $orderId],
                (string) $template['body']
            );

            $this->messageService->sendThanksMessage($orderId, $sellerId, $buyerId, $message);
            $processed++;
        }

        return $processed;
    }

    public function listOrders(string $status = 'paid', int $limit = 20, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $apiConfig = $this->settingsRepository->getApiConfig();
        if (!$apiConfig || empty($apiConfig['seller_id'])) {
            throw new \RuntimeException('Seller ID não configurado em Configurar API.');
        }

        $accessToken = $this->tokenService->getValidAccessToken();
        $sellerId = trim((string) $apiConfig['seller_id']);
        if (!ctype_digit($sellerId)) {
            throw new \RuntimeException('Seller ID inválido. Informe o user_id numérico da conta vendedora no Mercado Livre.');
        }

        $query = $this->buildOrdersSearchQuery($sellerId, $status, $limit, $dateFrom, $dateTo);
        $ordersResult = $this->client->get('/orders/search?' . $query, $accessToken);

        if ($ordersResult['status'] < 200 || $ordersResult['status'] >= 300) {
            $raw = (string) ($ordersResult['raw'] ?? '');
            $raw = substr($raw, 0, 300);
            $message = 'Falha ao buscar pedidos. HTTP: ' . $ordersResult['status'];

            if ((int) $ordersResult['status'] === 403) {
                $message .= '. A conta/token atual não tem permissão para consultar pedidos deste seller.';
            }

            if ($raw !== '') {
                $message .= ' Resposta da API: ' . $raw;
            }

            throw new \RuntimeException($message);
        }

        return $ordersResult['body']['results'] ?? [];
    }

    /**
     * Uma página de GET /orders/search com metadados de paginação.
     *
     * @return array{results: list<array<string, mixed>>, paging: array<string, mixed>}
     */
    public function searchOrdersPage(
        string $status = 'paid',
        int $limit = 50,
        int $offset = 0,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        $apiConfig = $this->settingsRepository->getApiConfig();
        if (!$apiConfig || empty($apiConfig['seller_id'])) {
            throw new \RuntimeException('Seller ID não configurado em Configurar API.');
        }

        $sellerId = trim((string) $apiConfig['seller_id']);
        if (!ctype_digit($sellerId)) {
            throw new \RuntimeException('Seller ID inválido. Informe o user_id numérico da conta vendedora no Mercado Livre.');
        }

        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $accessToken = $this->tokenService->getValidAccessToken();
        $query = $this->buildOrdersSearchQuery($sellerId, $status, $limit, $dateFrom, $dateTo)
            . '&offset=' . $offset;
        $ordersResult = $this->client->get('/orders/search?' . $query, $accessToken);

        if ($ordersResult['status'] < 200 || $ordersResult['status'] >= 300) {
            $raw = (string) ($ordersResult['raw'] ?? '');
            $message = 'Falha ao buscar pedidos. HTTP: ' . $ordersResult['status'];
            if ($raw !== '') {
                $message .= ' Resposta da API: ' . substr($raw, 0, 300);
            }

            throw new \RuntimeException($message);
        }

        $body = is_array($ordersResult['body'] ?? null) ? $ordersResult['body'] : [];
        $results = $body['results'] ?? [];
        $paging = is_array($body['paging'] ?? null) ? $body['paging'] : [];

        return [
            'results' => is_array($results) ? $results : [],
            'paging' => $paging,
        ];
    }

    /**
     * Pedidos pagos no período, com paginação automática (limite de segurança).
     *
     * @return array{orders: list<array<string, mixed>>, total_api: int, truncated: bool}
     */
    public function listPaidOrdersInPeriod(string $dateFrom, string $dateTo, int $maxOrders = 2000): array
    {
        $maxOrders = max(1, min(5000, $maxOrders));
        $all = [];
        $offset = 0;
        $totalApi = 0;

        do {
            $page = $this->searchOrdersPage('paid', 50, $offset, $dateFrom, $dateTo);
            $batch = $page['results'];
            $totalApi = (int) ($page['paging']['total'] ?? $totalApi);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $order) {
                if (!is_array($order)) {
                    continue;
                }
                $all[] = $order;
                if (count($all) >= $maxOrders) {
                    break 2;
                }
            }

            $offset += count($batch);
            if (count($batch) < 50) {
                break;
            }
        } while ($offset < 10000);

        return [
            'orders' => $all,
            'total_api' => $totalApi > 0 ? $totalApi : count($all),
            'truncated' => $totalApi > count($all),
        ];
    }

    /**
     * Busca pedidos por texto livre (parâmetro {@code q} em {@code GET /orders/search}), sem filtrar por status.
     * Mais eficiente que listar vários status no período quando se conhece id ou trecho do pedido.
     *
     * @return list<array<string, mixed>>
     */
    public function searchOrdersByQuery(string $q, int $limit = 30): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }

        $apiConfig = $this->settingsRepository->getApiConfig();
        if (!$apiConfig || empty($apiConfig['seller_id'])) {
            throw new \RuntimeException('Seller ID não configurado em Configurar API.');
        }

        $sellerId = trim((string) $apiConfig['seller_id']);
        if (!ctype_digit($sellerId)) {
            throw new \RuntimeException('Seller ID inválido. Informe o user_id numérico da conta vendedora no Mercado Livre.');
        }

        $limit = max(1, min(50, $limit));
        $accessToken = $this->tokenService->getValidAccessToken();
        $path = '/orders/search?seller=' . urlencode($sellerId)
            . '&q=' . rawurlencode($q)
            . '&sort=date_desc&limit=' . $limit;
        $ordersResult = $this->client->get($path, $accessToken);

        if ($ordersResult['status'] < 200 || $ordersResult['status'] >= 300) {
            return [];
        }

        $results = $ordersResult['body']['results'] ?? [];

        return is_array($results) ? $results : [];
    }

    public function getOrderById(string $orderId): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '' || !ctype_digit($orderId)) {
            return null;
        }

        $accessToken = $this->tokenService->getValidAccessToken();
        $orderResult = $this->client->get('/orders/' . urlencode($orderId), $accessToken);
        if ($orderResult['status'] < 200 || $orderResult['status'] >= 300) {
            return null;
        }

        $body = $orderResult['body'] ?? null;

        return is_array($body) ? $body : null;
    }

    /**
     * Mesma query string usada em {@see listOrders} para GET /orders/search (sem o prefixo do path).
     */
    private function buildOrdersSearchQuery(
        string $sellerId,
        string $status,
        int $limit,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): string {
        $status = trim($status) !== '' ? trim($status) : 'paid';
        $limit = max(1, min(50, $limit));
        $dateFromNorm = $this->normalizeDate($dateFrom, false);
        $dateToNorm = $this->normalizeDate($dateTo, true);

        $query = 'seller=' . urlencode($sellerId)
            . '&order.status=' . urlencode($status)
            . '&sort=date_desc'
            . '&limit=' . $limit;

        if ($dateFromNorm !== null) {
            $query .= '&order.date_created.from=' . urlencode($dateFromNorm);
        }

        if ($dateToNorm !== null) {
            $query .= '&order.date_created.to=' . urlencode($dateToNorm);
        }

        return $query;
    }

    public function findOrderIdByOperation(string $operationValue): ?string
    {
        $match = $this->findRepasseMatchByOperation($operationValue);

        return $match['order_id'] ?? null;
    }

    /**
     * Resolve a coluna "Operação relacionada" contra o JSON do pedido na API:
     * compara com payments[].id e, se não houver coincidência em pagamentos, com order.id.
     *
     * @return array{order_id: string, payment_id: ?string}|null payment_id é o id em payments quando o match veio de um pagamento
     */
    public function findRepasseMatchByOperation(string $operationValue): ?array
    {
        return $this->findRepasseMatchByOperationWithTrace($operationValue)['match'];
    }

    /**
     * Igual a findRepasseMatchByOperation, mas retorna cada resposta HTTP consultada (para depuração na tela de repasse).
     *
     * @return array{
     *     match: ?array{order_id: string, payment_id: ?string},
     *     trace: list<array{method: string, path: string, http_status: int, response_body: mixed, response_raw_excerpt: ?string}>
     * }
     */
    public function findRepasseMatchByOperationWithTrace(string $operationValue): array
    {
        $trace = [];
        $operationValue = trim($operationValue);
        if ($operationValue === '') {
            return ['match' => null, 'trace' => []];
        }

        $apiConfig = $this->settingsRepository->getApiConfig();
        if (!$apiConfig || empty($apiConfig['seller_id'])) {
            throw new \RuntimeException('Seller ID não configurado em Configurar API.');
        }

        $sellerId = trim((string) $apiConfig['seller_id']);
        if (!ctype_digit($sellerId)) {
            throw new \RuntimeException('Seller ID inválido. Informe o user_id numérico da conta vendedora no Mercado Livre.');
        }

        $accessToken = $this->tokenService->getValidAccessToken();
        $needle = $this->normalizeOperationId($operationValue);

        if (ctype_digit($operationValue)) {
            $path = '/orders/' . urlencode($operationValue);
            $orderResult = $this->client->get($path, $accessToken);
            $this->appendRepasseTrace($trace, $path, $orderResult);
            if ($orderResult['status'] >= 200 && $orderResult['status'] < 300) {
                $body = $orderResult['body'];
                if (is_array($body)) {
                    $resolved = $this->resolveRepasseMatchFromOrderBody($body, $needle);
                    if ($resolved !== null) {
                        return ['match' => $resolved, 'trace' => $trace];
                    }
                }
            }
        }

        // Uma busca na API com o valor da coluna D como filtro (parâmetro q), como documentado em orders/search.
        $searchPath = '/orders/search?seller=' . urlencode($sellerId)
            . '&q=' . urlencode($operationValue)
            . '&sort=date_desc&limit=50';
        $searchResult = $this->client->get($searchPath, $accessToken);
        $this->appendRepasseTrace($trace, $searchPath, $searchResult);

        if ($searchResult['status'] < 200 || $searchResult['status'] >= 300) {
            return ['match' => null, 'trace' => $trace];
        }

        $results = $searchResult['body']['results'] ?? [];
        if (!is_array($results)) {
            return ['match' => null, 'trace' => $trace];
        }

        $idsForDetail = [];
        foreach ($results as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $candidateId = (string) ($candidate['id'] ?? '');
            if ($candidateId === '' || !ctype_digit($candidateId)) {
                continue;
            }

            $resolved = $this->resolveRepasseMatchFromOrderBody($candidate, $needle);
            if ($resolved !== null) {
                return ['match' => $resolved, 'trace' => $trace];
            }

            $idsForDetail[] = $candidateId;
        }

        // Só busca detalhe completo quando o resumo da search não basta (ex.: payments incompleto). Limite para não travar a planilha.
        $maxDetailRequests = 20;
        foreach ($idsForDetail as $candidateId) {
            if ($maxDetailRequests-- <= 0) {
                break;
            }

            $detailPath = '/orders/' . urlencode($candidateId);
            $detail = $this->client->get($detailPath, $accessToken);
            $this->appendRepasseTrace($trace, $detailPath, $detail);
            if ($detail['status'] < 200 || $detail['status'] >= 300) {
                continue;
            }

            $body = $detail['body'];
            if (!is_array($body)) {
                continue;
            }

            $resolved = $this->resolveRepasseMatchFromOrderBody($body, $needle);
            if ($resolved !== null) {
                return ['match' => $resolved, 'trace' => $trace];
            }
        }

        return ['match' => null, 'trace' => $trace];
    }

    /**
     * @param list<array{method: string, path: string, http_status: int, response_body: mixed, response_raw_excerpt: ?string}> $trace
     */
    private function appendRepasseTrace(array &$trace, string $path, array $result): void
    {
        $raw = isset($result['raw']) && is_string($result['raw']) ? $result['raw'] : null;
        $excerpt = null;
        if ($raw !== null && $raw !== '') {
            $max = 16000;
            $excerpt = strlen($raw) > $max ? substr($raw, 0, $max) . "\n… [truncado]" : $raw;
        }

        $bodyForTrace = $result['body'] ?? null;
        if (is_array($bodyForTrace) && str_contains($path, '/orders/search') && isset($bodyForTrace['results']) && is_array($bodyForTrace['results'])) {
            $total = count($bodyForTrace['results']);
            if ($total > 10) {
                $bodyForTrace['results'] = array_slice($bodyForTrace['results'], 0, 10);
                $bodyForTrace['_repasse_modal_note'] = 'Lista results limitada a 10 de ' . $total . ' itens retornados pela API.';
            }
        }

        $trace[] = [
            'method' => 'GET',
            'path' => $path,
            'http_status' => (int) ($result['status'] ?? 0),
            'response_body' => $bodyForTrace,
            'response_raw_excerpt' => $excerpt,
        ];
    }

    /**
     * Confere o valor da coluna "Operação relacionada" no resultado do pedido:
     * primeiro em payments[].id; se não bater, em order.id.
     *
     * @return array{order_id: string, payment_id: ?string}|null
     */
    private function resolveRepasseMatchFromOrderBody(array $orderBody, string $needle): ?array
    {
        $orderId = (string) ($orderBody['id'] ?? '');
        if ($orderId === '') {
            return null;
        }

        if (isset($orderBody['payments']) && is_array($orderBody['payments'])) {
            foreach ($orderBody['payments'] as $payment) {
                if (!is_array($payment) || !isset($payment['id'])) {
                    continue;
                }
                $pid = (string) $payment['id'];
                if ($this->normalizeOperationId($pid) === $needle) {
                    return ['order_id' => $orderId, 'payment_id' => $pid];
                }
            }
        }

        if ($this->normalizeOperationId($orderId) === $needle) {
            return ['order_id' => $orderId, 'payment_id' => null];
        }

        return null;
    }

    private function normalizeOperationId(string $value): string
    {
        return trim($value);
    }

    private function normalizeDate(?string $date, bool $endOfDay): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }

        $dateTime = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$dateTime || $dateTime->format('Y-m-d') !== $date) {
            throw new \RuntimeException('Data inválida. Use o formato YYYY-MM-DD.');
        }

        if ($endOfDay) {
            $dateTime->setTime(23, 59, 59);
        } else {
            $dateTime->setTime(0, 0, 0);
        }

        return $dateTime->format(\DateTimeInterface::ATOM);
    }
}
