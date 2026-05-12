<?php

declare(strict_types=1);

namespace App\Services;

final class LexosOrderTimelineSupport
{
  /**
   * @param array<string, list<array<string, mixed>>> $timelines
   * @return array{
   *   timelines: array<string, list<array<string, mixed>>>,
   *   orders: list<array<string, mixed>>,
   *   summary: array<string, int>,
   *   start_date: string,
   *   end_date: string
   * }
   */
  public static function buildMonitorSnapshot(array $timelines, string $startDate, string $endDate): array
  {
    $timelines = self::sortTimelines($timelines);

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
      $category = self::categorizeStatus($status);
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
      'timelines' => $timelines,
      'orders' => $orders,
      'summary' => $summary,
      'start_date' => $startDate,
      'end_date' => $endDate,
    ];
  }

  /**
   * @param array<string, mixed> $row
   * @return array{status: string, date: string, action: string, category: string, row: array<string, mixed>}
   */
  public static function buildTimelineEvent(array $row): array
  {
    $status = self::extractStatus($row);

    return [
      'status' => $status,
      'date' => self::extractEventDate($row),
      'action' => self::recommendedActionForStatus($status),
      'category' => self::categorizeStatus($status),
      'row' => $row,
    ];
  }

  /**
   * @param array<string, mixed> $payload
   * @return array{order_id: string, status: string, event_type: string, event_date: string}
   */
  public static function extractEventFields(array $payload): array
  {
    return [
      'order_id' => self::extractOrderIdentifier($payload),
      'status' => self::extractStatus($payload),
      'event_type' => self::extractEventType($payload),
      'event_date' => self::extractEventDate($payload),
    ];
  }

  /**
   * @param array<string, list<array<string, mixed>>> $timelines
   * @return array<string, list<array<string, mixed>>>
   */
  public static function sortTimelines(array $timelines): array
  {
    foreach ($timelines as $orderId => $events) {
      usort($events, static function (array $a, array $b): int {
        return strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
      });
      $timelines[$orderId] = $events;
    }

    return $timelines;
  }

  /**
   * @param array<string, mixed> $row
   */
  public static function extractOrderIdentifier(array $row): string
  {
    $candidates = [
      'Pedido', 'NumeroPedido', 'CodigoPedido', 'Codigo', 'Numero', 'IdPedido', 'OrderId',
      'order_id', 'orderId', 'numero_pedido', 'numeroPedido', 'id_pedido', 'idPedido',
    ];
    foreach ($candidates as $key) {
      $value = trim((string) ($row[$key] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }

    return 'sem_identificador';
  }

  /**
   * @param array<string, mixed> $row
   */
  public static function extractStatus(array $row): string
  {
    $candidates = [
      'Status', 'StatusPedido', 'Situacao', 'SituacaoPedido', 'Estado', 'StatusIntegracao',
      'status', 'status_pedido', 'statusPedido', 'situacao', 'situacaoPedido',
    ];
    foreach ($candidates as $key) {
      $value = trim((string) ($row[$key] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }

    return 'Status não identificado';
  }

  /**
   * @param array<string, mixed> $row
   */
  public static function extractEventType(array $row): string
  {
    $candidates = ['event', 'evento', 'tipo', 'type', 'acao', 'action', 'topic', 'name'];
    foreach ($candidates as $key) {
      $value = trim((string) ($row[$key] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }

    return '';
  }

  /**
   * @param array<string, mixed> $row
   */
  public static function extractEventDate(array $row): string
  {
    $candidates = [
      'DataAtualizacao', 'DataAlteracao', 'DataStatus', 'DataPedido', 'DataCriacao', 'Data',
      'data', 'dataAtualizacao', 'data_atualizacao', 'dataAlteracao', 'data_alteracao',
      'dataStatus', 'data_status', 'dataPedido', 'data_pedido', 'created_at', 'updated_at',
      'timestamp', 'eventDate', 'event_date',
    ];
    foreach ($candidates as $key) {
      $value = trim((string) ($row[$key] ?? ''));
      if ($value !== '') {
        return $value;
      }
    }

    return '';
  }

  public static function recommendedActionForStatus(string $status): string
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

  public static function categorizeStatus(string $status): string
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
      || str_contains($s, 'paid')
      || str_contains($s, 'confirm')
    ) {
      return 'aberto';
    }

    return 'outros';
  }
}
