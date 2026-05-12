<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class MercadoLivreOrderMonitorService
{
    /** @var list<string> */
    private const STATUSES = ['paid', 'confirmed', 'payment_required', 'cancelled'];

    public function __construct(private OrderService $orderService)
    {
    }

    public function monitorPeriod(string $startDate, string $endDate, int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $ordersById = [];

        foreach (self::STATUSES as $status) {
            if (count($ordersById) >= $limit) {
                break;
            }

            try {
                $chunk = $this->orderService->listOrders(
                    $status,
                    min(50, $limit - count($ordersById)),
                    $startDate,
                    $endDate
                );
            } catch (RuntimeException) {
                continue;
            }

            foreach ($chunk as $order) {
                if (!is_array($order)) {
                    continue;
                }

                $orderId = trim((string) ($order['id'] ?? ''));
                if ($orderId === '') {
                    continue;
                }

                $ordersById[$orderId] = $order;
                if (count($ordersById) >= $limit) {
                    break;
                }
            }
        }

        $timelines = [];
        foreach ($ordersById as $orderId => $order) {
            $timelines[$orderId][] = LexosOrderTimelineSupport::buildTimelineEvent($this->mapMercadoLivreOrder($order));
        }

        $snapshot = LexosOrderTimelineSupport::buildMonitorSnapshot($timelines, $startDate, $endDate);
        $snapshot['source'] = 'mercado_livre';

        return $snapshot;
    }

    public function findOrderTimeline(string $orderQuery, string $startDate, string $endDate, int $limit = 500): array
    {
        $orderQuery = trim($orderQuery);
        if ($orderQuery === '') {
            throw new RuntimeException('Informe um número de pedido para monitorar.');
        }

        $monitor = $this->monitorPeriod($startDate, $endDate, max(1, min(5000, $limit)));
        $timelines = [];
        foreach ($monitor['timelines'] as $orderId => $events) {
            if (stripos((string) $orderId, $orderQuery) === false) {
                continue;
            }

            $timelines[$orderId] = $events;
        }

        return [
            'query' => $orderQuery,
            'timelines' => $timelines,
        ];
    }

    public function getRecentOrderExample(string $startDate, string $endDate): ?string
    {
        $monitor = $this->monitorPeriod($startDate, $endDate, 1);
        $orders = is_array($monitor['orders'] ?? null) ? $monitor['orders'] : [];
        if ($orders === []) {
            return null;
        }

        return trim((string) ($orders[0]['order_id'] ?? '')) ?: null;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function mapMercadoLivreOrder(array $order): array
    {
        return [
            'Pedido' => (string) ($order['id'] ?? ''),
            'Status' => (string) ($order['status'] ?? ''),
            'DataPedido' => (string) ($order['date_created'] ?? ''),
            'source' => 'mercado_livre',
            'buyer' => (string) ($order['buyer']['nickname'] ?? ''),
            'total' => (string) ($order['total_amount'] ?? ''),
        ];
    }
}
