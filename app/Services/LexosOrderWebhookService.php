<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LexosOrderWebhookRepository;
use RuntimeException;

class LexosOrderWebhookService
{
    public function __construct(private LexosOrderWebhookRepository $repository)
    {
    }

    /**
     * @return array{ok: bool, stored: int, duplicates: int, received: int, ignored: int}
     */
    public function ingestPayload(string $rawBody): array
    {
        $rawBody = trim($rawBody);
        if ($rawBody === '') {
            $this->repository->registerDelivery('', 0, 0, 0, 'Payload vazio.');
            throw new RuntimeException('Payload vazio.');
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            $this->repository->registerDelivery($rawBody, 0, 0, 0, 'Payload JSON inválido.');
            throw new RuntimeException('Payload JSON inválido.');
        }

        $items = $this->normalizePayloadItems($decoded);
        $stored = 0;
        $duplicates = 0;
        $ignored = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                $ignored++;
                continue;
            }

            $fields = LexosOrderTimelineSupport::extractEventFields($item);
            $orderId = $fields['order_id'];
            if ($orderId === '' || $orderId === 'sem_identificador') {
                $ignored++;
                continue;
            }

            $payloadJson = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $eventKey = hash('sha256', $orderId . '|' . $fields['status'] . '|' . $fields['event_type'] . '|' . $fields['event_date'] . '|' . $payloadJson);
            $result = $this->repository->storeEvent(
                $orderId,
                $fields['status'],
                $fields['event_type'],
                $fields['event_date'] !== '' ? $fields['event_date'] : null,
                $payloadJson,
                $eventKey
            );

            if ($result['inserted']) {
                $stored++;
            } else {
                $duplicates++;
            }
        }

        $this->repository->registerDelivery($rawBody, $stored, $duplicates, $ignored, null);

        return [
            'ok' => true,
            'stored' => $stored,
            'duplicates' => $duplicates,
            'received' => count($items),
            'ignored' => $ignored,
        ];
    }

    /**
     * @return array{deliveries: int, last_received_at: string|null}
     */
    public function getDeliveryStats(): array
    {
        return $this->repository->getDeliveryStats();
    }

    public function monitorPeriod(string $startDate, string $endDate, int $limit = 5000): array
    {
        $events = $this->repository->listEvents($startDate, $endDate, null, $limit);
        $timelines = $this->buildTimelinesFromStoredEvents($events);

        $snapshot = LexosOrderTimelineSupport::buildMonitorSnapshot($timelines, $startDate, $endDate);
        $snapshot['source'] = 'webhook';

        return $snapshot;
    }

    public function findOrderTimeline(string $orderQuery, string $startDate, string $endDate, int $limit = 500): array
    {
        $orderQuery = trim($orderQuery);
        if ($orderQuery === '') {
            throw new RuntimeException('Informe um número de pedido para monitorar.');
        }

        $events = $this->repository->listEvents($startDate, $endDate, $orderQuery, max(1, min(5000, $limit)));
        $timelines = $this->buildTimelinesFromStoredEvents($events);

        return [
            'query' => $orderQuery,
            'timelines' => $timelines,
        ];
    }

    public function getRecentOrderExample(string $startDate, string $endDate): ?string
    {
        return $this->repository->findRecentOrderId($startDate, $endDate);
    }

    public function countStoredEvents(): int
    {
        return $this->repository->countEvents();
    }

    /**
     * @param array<string, mixed> $decoded
     * @return list<array<string, mixed>>
     */
    private function normalizePayloadItems(array $decoded): array
    {
        if ($this->isList($decoded)) {
            $items = [];
            foreach ($decoded as $entry) {
                if (is_array($entry)) {
                    $items = array_merge($items, $this->normalizePayloadItems($entry));
                }
            }

            return $items !== [] ? $items : array_values(array_filter($decoded, 'is_array'));
        }

        foreach (['pedidos', 'Pedidos', 'items', 'Items', 'events', 'Events', 'data', 'Data', 'result', 'Result'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_array($value) && $this->isList($value)) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        if (isset($decoded['data']) && is_array($decoded['data']) && !$this->isList($decoded['data'])) {
            return [$this->mergePayloadLayers($decoded, $decoded['data'])];
        }

        if (isset($decoded['pedido']) && is_array($decoded['pedido'])) {
            return [$this->mergePayloadLayers($decoded, $decoded['pedido'])];
        }

        return [$decoded];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, list<array<string, mixed>>>
     */
    private function buildTimelinesFromStoredEvents(array $events): array
    {
        $timelines = [];

        foreach ($events as $event) {
            $payload = json_decode((string) ($event['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                $payload = [];
            }

            $row = array_merge($payload, [
                'Pedido' => (string) ($event['order_id'] ?? ''),
                'Status' => (string) ($event['status'] ?? ''),
                'DataAtualizacao' => (string) (($event['event_date'] ?? '') !== '' ? $event['event_date'] : ($event['received_at'] ?? '')),
                'event_type' => (string) ($event['event_type'] ?? ''),
            ]);

            $orderId = LexosOrderTimelineSupport::extractOrderIdentifier($row);
            $timelines[$orderId][] = LexosOrderTimelineSupport::buildTimelineEvent($row);
        }

        return LexosOrderTimelineSupport::sortTimelines($timelines);
    }

    /**
     * @param array<string, mixed> $outer
     * @param array<string, mixed> $inner
     * @return array<string, mixed>
     */
    private function mergePayloadLayers(array $outer, array $inner): array
    {
        $merged = $inner;
        foreach (['event', 'evento', 'tipo', 'type', 'acao', 'action', 'topic', 'name'] as $key) {
            if (!isset($merged[$key]) && isset($outer[$key])) {
                $merged[$key] = $outer[$key];
            }
        }

        return $merged;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
