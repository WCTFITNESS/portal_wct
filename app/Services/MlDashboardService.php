<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Dashboard de vendas baseado na API do Mercado Livre (pedidos pagos).
 */
final class MlDashboardService
{
    private const MAX_ORDERS_DEFAULT = 2000;

    public function __construct(
        private OrderService $orderService,
        private MercadoLivreClient $mercadoLivreClient,
        private TokenService $tokenService,
    ) {
    }

    /**
     * @return array{
     *   faturamento: float,
     *   pedidos: int,
     *   ticket_medio: float,
     *   listing_types: list<array{0: string, 1: float}>,
     *   total_api: int,
     *   truncated: bool
     * }
     */
    public function getDashboardMetrics(string $startDate, string $endDate, int $maxOrders = self::MAX_ORDERS_DEFAULT): array
    {
        $bundle = $this->orderService->listPaidOrdersInPeriod($startDate, $endDate, $maxOrders);
        $orders = $bundle['orders'];
        $revenue = 0.0;
        $byListing = [];

        foreach ($orders as $order) {
            $amount = $this->orderRevenue($order);
            $revenue += $amount;
            $items = $this->orderItems($order);
            $divisor = max(1, count($items));
            foreach ($items as $item) {
                $lt = $this->listingTypeLabel((string) ($item['listing_type'] ?? ''));
                $byListing[$lt] = ($byListing[$lt] ?? 0.0) + ($amount / $divisor);
            }
        }

        $count = count($orders);
        $listingRows = [];
        foreach ($byListing as $label => $value) {
            $listingRows[] = [$label, round($value, 2)];
        }
        usort($listingRows, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return [
            'faturamento' => round($revenue, 2),
            'pedidos' => $count,
            'ticket_medio' => $count > 0 ? round($revenue / $count, 2) : 0.0,
            'listing_types' => $listingRows,
            'total_api' => (int) $bundle['total_api'],
            'truncated' => (bool) $bundle['truncated'],
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, count: int, truncated: bool, total_api: int}
     */
    public function getProducts(
        string $startDate,
        string $endDate,
        string $search = '',
        int $take = 50,
        int $skip = 0,
        int $maxOrders = self::MAX_ORDERS_DEFAULT
    ): array {
        $bundle = $this->orderService->listPaidOrdersInPeriod($startDate, $endDate, $maxOrders);
        $agg = [];

        foreach ($bundle['orders'] as $order) {
            foreach ($this->orderItems($order) as $line) {
                $sku = $this->lineSku($line);
                $title = trim((string) ($line['item']['title'] ?? ''));
                $key = $sku !== '' ? $sku : (string) ($line['item']['id'] ?? $title);
                if ($key === '') {
                    continue;
                }
                if ($search !== '' && !$this->textMatches($search, $sku . ' ' . $title . ' ' . (string) ($line['item']['id'] ?? ''))) {
                    continue;
                }

                $qty = (float) ($line['quantity'] ?? 0);
                $lineTotal = (float) ($line['unit_price'] ?? 0) * $qty;
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'Sku' => $sku !== '' ? $sku : $key,
                        'Nome' => $title,
                        'Mlb' => (string) ($line['item']['id'] ?? ''),
                        'TotalVendidoItem' => 0.0,
                        'TotalUnidadesVendidas' => 0.0,
                    ];
                }
                $agg[$key]['TotalVendidoItem'] += $lineTotal;
                $agg[$key]['TotalUnidadesVendidas'] += $qty;
            }
        }

        $rows = array_values($agg);
        usort($rows, static fn (array $a, array $b): int => ($b['TotalVendidoItem'] ?? 0) <=> ($a['TotalVendidoItem'] ?? 0));

        $take = max(1, min(200, $take));
        $skip = max(0, $skip);

        return [
            'items' => array_slice($rows, $skip, $take),
            'count' => count($rows),
            'truncated' => (bool) $bundle['truncated'],
            'total_api' => (int) $bundle['total_api'],
        ];
    }

    /**
     * @param list<string|int> $years
     * @return array{months: list<string>, series: array<string, list<float>>}
     */
    public function getComparisonData(array $years): array
    {
        $months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        $series = [];
        $now = new \DateTimeImmutable('now');

        foreach ($years as $yearRaw) {
            $year = (int) $yearRaw;
            if ($year < 2000 || $year > 2100) {
                continue;
            }

            $values = array_fill(0, 12, 0.0);
            $maxMonth = $year === (int) $now->format('Y') ? (int) $now->format('n') : 12;

            for ($m = 1; $m <= $maxMonth; $m++) {
                $start = sprintf('%04d-%02d-01', $year, $m);
                $endDt = (new \DateTimeImmutable($start))->modify('last day of this month');
                if ($year === (int) $now->format('Y') && $m === (int) $now->format('n')) {
                    $endDt = $now;
                }
                $end = $endDt->format('Y-m-d');

                try {
                    $bundle = $this->orderService->listPaidOrdersInPeriod($start, $end, 1500);
                    foreach ($bundle['orders'] as $order) {
                        $values[$m - 1] += $this->orderRevenue($order);
                    }
                } catch (RuntimeException) {
                    $values[$m - 1] = 0.0;
                }
            }

            $series[(string) $year] = array_map(static fn (float $v): float => round($v, 2), $values);
        }

        return ['months' => $months, 'series' => $series];
    }

    /**
     * @return array{
     *   sales: array{result: list<array<string, mixed>>},
     *   stock: array{result: list<array<string, mixed>>},
     *   summary: array<string, float|int|string|null>
     * }
     */
    public function getSkuAnalysis(string $sku, string $startDate, string $endDate): array
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('Informe o SKU para análise.');
        }

        $bundle = $this->orderService->listPaidOrdersInPeriod($startDate, $endDate, self::MAX_ORDERS_DEFAULT);
        $salesRows = [];
        $title = '';
        $itemId = '';

        foreach ($bundle['orders'] as $order) {
            $dateKey = substr((string) ($order['date_created'] ?? ''), 0, 10);
            if ($dateKey === '') {
                continue;
            }

            foreach ($this->orderItems($order) as $line) {
                $lineSku = $this->lineSku($line);
                if (!$this->textMatches($sku, $lineSku)) {
                    continue;
                }

                $qty = (float) ($line['quantity'] ?? 0);
                $valor = (float) ($line['unit_price'] ?? 0) * $qty;
                $salesRows[] = [
                    'DataAprovacao' => $dateKey . 'T12:00:00.000Z',
                    'Valor' => $valor,
                    'Qtde' => $qty,
                    'Sku' => $lineSku,
                    'Nome' => (string) ($line['item']['title'] ?? ''),
                ];
                if ($title === '') {
                    $title = (string) ($line['item']['title'] ?? '');
                }
                if ($itemId === '') {
                    $itemId = (string) ($line['item']['id'] ?? '');
                }
            }
        }

        $stockQty = $itemId !== '' ? $this->fetchItemAvailableQuantity($itemId) : null;

        return [
            'sales' => ['result' => $salesRows],
            'stock' => [
                'result' => $stockQty !== null ? [['Quantidade' => $stockQty, 'Nome' => $title, 'Sku' => $sku]] : [],
            ],
            'summary' => [
                'sku' => $sku,
                'title' => $title,
                'item_id' => $itemId,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $order
     */
    private function orderRevenue(array $order): float
    {
        $paid = (float) ($order['paid_amount'] ?? 0);
        if ($paid > 0) {
            return $paid;
        }

        return (float) ($order['total_amount'] ?? 0);
    }

    /**
     * @param array<string, mixed> $order
     * @return list<array<string, mixed>>
     */
    private function orderItems(array $order): array
    {
        $items = $order['order_items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $line) {
            if (is_array($line)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function lineSku(array $line): string
    {
        $item = is_array($line['item'] ?? null) ? $line['item'] : [];

        return trim((string) ($item['seller_sku'] ?? $item['seller_custom_field'] ?? ''));
    }

    private function listingTypeLabel(string $listingType): string
    {
        return match ($listingType) {
            'gold_special', 'gold_premium' => 'Premium',
            'gold_pro' => 'Clássico',
            'free' => 'Grátis',
            default => $listingType !== '' ? $listingType : 'Mercado Livre',
        };
    }

    private function textMatches(string $needle, string $haystack): bool
    {
        return stripos($haystack, $needle) !== false;
    }

    private function fetchItemAvailableQuantity(string $itemId): ?float
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return null;
        }

        try {
            $token = $this->tokenService->getValidAccessToken();
            $result = $this->mercadoLivreClient->get('/items/' . rawurlencode($itemId), $token);
            if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300) {
                return null;
            }
            $body = is_array($result['body'] ?? null) ? $result['body'] : [];

            return isset($body['available_quantity']) ? (float) $body['available_quantity'] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
