<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class MercadoLivreOrderMonitorService
{
    /** @var list<string> */
    private const STATUSES = ['paid', 'confirmed', 'payment_required', 'payment_in_process', 'cancelled'];

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

        $direct = $this->buildMonitorFromOrderId($orderQuery, $startDate, $endDate);
        if ($direct !== null && is_array($direct['timelines'] ?? null) && $direct['timelines'] !== []) {
            return [
                'query' => $orderQuery,
                'timelines' => $direct['timelines'],
            ];
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

    public function buildMonitorFromOrderId(string $orderId, string $startDate, string $endDate): ?array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        $order = $this->orderService->getOrderById($orderId);
        if ($order === null) {
            return null;
        }

        $createdAt = trim((string) ($order['date_created'] ?? ''));
        if ($createdAt !== '') {
            try {
                $created = new \DateTimeImmutable($createdAt);
                $start = new \DateTimeImmutable($startDate . ' 00:00:00');
                $end = new \DateTimeImmutable($endDate . ' 23:59:59');
                if ($created < $start || $created > $end) {
                    return null;
                }
            } catch (\Throwable) {
            }
        }

        $row = $this->mapMercadoLivreOrder($order);
        $timelines = [
            (string) ($order['id'] ?? $orderId) => [LexosOrderTimelineSupport::buildTimelineEvent($row)],
        ];
        $snapshot = LexosOrderTimelineSupport::buildMonitorSnapshot($timelines, $startDate, $endDate);
        $snapshot['source'] = 'mercado_livre';

        return $snapshot;
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
        $nested = $order['order'] ?? null;
        $nestedOrder = is_array($nested) ? $nested : [];

        $status = trim((string) ($order['status'] ?? $nestedOrder['status'] ?? ''));
        $shipping = $order['shipping'] ?? null;
        $shippingStatus = '';
        if (is_array($shipping)) {
            $shippingStatus = trim((string) ($shipping['status'] ?? ''));
        }
        if ($shippingStatus !== '') {
            $status = $status !== '' ? $status . ' / envio: ' . $shippingStatus : $shippingStatus;
        }

        return array_merge($order, [
            'Pedido' => (string) ($order['id'] ?? ''),
            'Status' => $status !== '' ? $status : 'Status não identificado',
            'DataPedido' => (string) ($order['date_created'] ?? ''),
            'source' => 'mercado_livre',
            'buyer' => (string) ($order['buyer']['nickname'] ?? ''),
            'total' => (string) ($order['total_amount'] ?? ''),
        ]);
    }
}
