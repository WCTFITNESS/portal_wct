<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use PDOException;

class LexosOrderWebhookRepository
{
  private bool $tableChecked = false;

  public function __construct(private PDO $pdo)
  {
  }

  /**
   * @return array{inserted: bool, id: int|null}
   */
  public function storeEvent(
    string $orderId,
    string $status,
    string $eventType,
    ?string $eventDate,
    string $payloadJson,
    string $eventKey
  ): array {
    $this->ensureTableExists();

    $sql = 'INSERT INTO lexos_order_webhook_events
            (order_id, status, event_type, event_date, payload_json, event_key, received_at)
            VALUES (:order_id, :status, :event_type, :event_date, :payload_json, :event_key, NOW())';

    try {
      $stmt = $this->pdo->prepare($sql);
      $stmt->execute([
        ':order_id' => $this->truncate($orderId, 120),
        ':status' => $this->truncate($status, 255),
        ':event_type' => $this->truncate($eventType, 120),
        ':event_date' => $this->normalizeDateTime($eventDate),
        ':payload_json' => $payloadJson,
        ':event_key' => $eventKey,
      ]);

      return ['inserted' => true, 'id' => (int) $this->pdo->lastInsertId()];
    } catch (PDOException $exception) {
      if ($this->isDuplicateKey($exception)) {
        return ['inserted' => false, 'id' => null];
      }

      throw $exception;
    }
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listEvents(string $startDate, string $endDate, ?string $orderQuery = null, int $limit = 5000): array
  {
    $this->ensureTableExists();
    $limit = max(1, min(10000, $limit));

    $start = $startDate . ' 00:00:00';
    $end = $endDate . ' 23:59:59';

    $sql = 'SELECT * FROM lexos_order_webhook_events
            WHERE COALESCE(event_date, received_at) >= :start_date
              AND COALESCE(event_date, received_at) <= :end_date';
    $params = [
      ':start_date' => $start,
      ':end_date' => $end,
      ':limit' => $limit,
    ];

    if ($orderQuery !== null && trim($orderQuery) !== '') {
      $sql .= ' AND order_id LIKE :order_query';
      $params[':order_query'] = '%' . trim($orderQuery) . '%';
    }

    $sql .= ' ORDER BY COALESCE(event_date, received_at) ASC, id ASC LIMIT :limit';

    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $key => $value) {
      $type = $key === ':limit' ? PDO::PARAM_INT : PDO::PARAM_STR;
      $stmt->bindValue($key, $value, $type);
    }
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  public function findRecentOrderId(string $startDate, string $endDate): ?string
  {
    $this->ensureTableExists();

    $stmt = $this->pdo->prepare(
      'SELECT order_id FROM lexos_order_webhook_events
       WHERE COALESCE(event_date, received_at) >= :start_date
         AND COALESCE(event_date, received_at) <= :end_date
         AND order_id <> :empty
       ORDER BY COALESCE(event_date, received_at) DESC, id DESC
       LIMIT 1'
    );
    $stmt->execute([
      ':start_date' => $startDate . ' 00:00:00',
      ':end_date' => $endDate . ' 23:59:59',
      ':empty' => 'sem_identificador',
    ]);

    $orderId = trim((string) ($stmt->fetchColumn() ?: ''));

    return $orderId !== '' ? $orderId : null;
  }

  public function countEvents(): int
  {
    $this->ensureTableExists();

    return (int) $this->pdo->query('SELECT COUNT(*) FROM lexos_order_webhook_events')->fetchColumn();
  }

  public function registerDelivery(string $payloadJson, int $stored, int $duplicates, int $ignored, ?string $errorMessage = null): void
  {
    $this->ensureTableExists();

    $stmt = $this->pdo->prepare(
      'INSERT INTO lexos_webhook_deliveries
       (payload_json, stored_count, duplicate_count, ignored_count, error_message, received_at)
       VALUES (:payload_json, :stored_count, :duplicate_count, :ignored_count, :error_message, NOW())'
    );
    $stmt->execute([
      ':payload_json' => $this->truncate($payloadJson, 20000),
      ':stored_count' => $stored,
      ':duplicate_count' => $duplicates,
      ':ignored_count' => $ignored,
      ':error_message' => $errorMessage !== null ? $this->truncate($errorMessage, 500) : null,
    ]);
  }

  /**
   * @return array{deliveries: int, last_received_at: string|null}
   */
  public function getDeliveryStats(): array
  {
    $this->ensureTableExists();

    $stmt = $this->pdo->query('SELECT COUNT(*) AS deliveries, MAX(received_at) AS last_received_at FROM lexos_webhook_deliveries');
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    return [
      'deliveries' => (int) ($row['deliveries'] ?? 0),
      'last_received_at' => isset($row['last_received_at']) && $row['last_received_at'] !== null
        ? (string) $row['last_received_at']
        : null,
    ];
  }

  private function ensureTableExists(): void
  {
    if ($this->tableChecked) {
      return;
    }

    $sql = $this->isPgsql()
      ? 'CREATE TABLE IF NOT EXISTS lexos_order_webhook_events (
            id BIGSERIAL PRIMARY KEY,
            order_id VARCHAR(120) NOT NULL,
            status VARCHAR(255) NOT NULL,
            event_type VARCHAR(120) DEFAULT NULL,
            event_date TIMESTAMP DEFAULT NULL,
            payload_json TEXT NOT NULL,
            event_key VARCHAR(64) NOT NULL,
            received_at TIMESTAMP NOT NULL,
            CONSTRAINT uq_lexos_order_webhook_event_key UNIQUE (event_key)
        )'
      : 'CREATE TABLE IF NOT EXISTS lexos_order_webhook_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id VARCHAR(120) NOT NULL,
            status VARCHAR(255) NOT NULL,
            event_type VARCHAR(120) DEFAULT NULL,
            event_date DATETIME DEFAULT NULL,
            payload_json TEXT NOT NULL,
            event_key VARCHAR(64) NOT NULL,
            received_at DATETIME NOT NULL,
            UNIQUE KEY uq_lexos_order_webhook_event_key (event_key),
            KEY idx_lexos_order_webhook_order (order_id),
            KEY idx_lexos_order_webhook_event_date (event_date),
            KEY idx_lexos_order_webhook_received (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $this->pdo->exec($sql);

    $deliverySql = $this->isPgsql()
      ? 'CREATE TABLE IF NOT EXISTS lexos_webhook_deliveries (
            id BIGSERIAL PRIMARY KEY,
            payload_json TEXT NOT NULL,
            stored_count INT NOT NULL DEFAULT 0,
            duplicate_count INT NOT NULL DEFAULT 0,
            ignored_count INT NOT NULL DEFAULT 0,
            error_message VARCHAR(500) DEFAULT NULL,
            received_at TIMESTAMP NOT NULL
        )'
      : 'CREATE TABLE IF NOT EXISTS lexos_webhook_deliveries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payload_json TEXT NOT NULL,
            stored_count INT NOT NULL DEFAULT 0,
            duplicate_count INT NOT NULL DEFAULT 0,
            ignored_count INT NOT NULL DEFAULT 0,
            error_message VARCHAR(500) DEFAULT NULL,
            received_at DATETIME NOT NULL,
            KEY idx_lexos_webhook_deliveries_received (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $this->pdo->exec($deliverySql);
    $this->tableChecked = true;
  }

  private function isPgsql(): bool
  {
    return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
  }

  private function isDuplicateKey(PDOException $exception): bool
  {
    $code = (string) $exception->getCode();
    if ($code === '23000' || $code === '23505') {
      return true;
    }

    $message = strtolower($exception->getMessage());

    return str_contains($message, 'duplicate')
      || str_contains($message, 'unique constraint')
      || str_contains($message, 'uq_lexos_order_webhook_event_key');
  }

  private function normalizeDateTime(?string $value): ?string
  {
    $value = trim((string) $value);
    if ($value === '') {
      return null;
    }

    try {
      $date = new \DateTimeImmutable($value);

      return $date->format('Y-m-d H:i:s');
    } catch (\Throwable) {
      return null;
    }
  }

  private function truncate(string $value, int $max): string
  {
    if (mb_strlen($value) <= $max) {
      return $value;
    }

    return mb_substr($value, 0, $max);
  }
}
