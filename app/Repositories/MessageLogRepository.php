<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class MessageLogRepository
{
    private bool $manualTableChecked = false;

    public function __construct(private PDO $pdo)
    {
    }

    public function wasOrderAlreadySent(string $orderId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM message_logs WHERE order_id = :order_id LIMIT 1');
        $stmt->execute([':order_id' => $orderId]);

        return (bool) $stmt->fetch();
    }

    public function register(
        string $orderId,
        string $receiverId,
        string $messageBody,
        string $status,
        ?string $apiResponse = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO message_logs (order_id, receiver_id, message_body, status, api_response, sent_at, created_at)
             VALUES (:order_id, :receiver_id, :message_body, :status, :api_response, NOW(), NOW())'
        );

        $stmt->execute([
            ':order_id' => $orderId,
            ':receiver_id' => $receiverId,
            ':message_body' => $messageBody,
            ':status' => $status,
            ':api_response' => $apiResponse,
        ]);
    }

    public function listRecent(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM message_logs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function registerManual(
        string $orderId,
        string $senderId,
        string $messageBody,
        string $status,
        ?string $apiResponse = null
    ): void {
        $this->ensureManualTableExists();

        $stmt = $this->pdo->prepare(
            'INSERT INTO manual_message_logs (order_id, sender_id, message_body, status, api_response, sent_at, created_at)
             VALUES (:order_id, :sender_id, :message_body, :status, :api_response, NOW(), NOW())'
        );

        $stmt->execute([
            ':order_id' => $orderId,
            ':sender_id' => $senderId,
            ':message_body' => $messageBody,
            ':status' => $status,
            ':api_response' => $apiResponse,
        ]);
    }

    public function getLatestManualMessage(): ?array
    {
        $this->ensureManualTableExists();

        $stmt = $this->pdo->query('SELECT * FROM manual_message_logs ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function listRecentManualMessages(int $limit = 10): array
    {
        $this->ensureManualTableExists();

        $stmt = $this->pdo->prepare('SELECT * FROM manual_message_logs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function ensureManualTableExists(): void
    {
        if ($this->manualTableChecked) {
            return;
        }

        $sql = $this->isPgsql()
            ? 'CREATE TABLE IF NOT EXISTS manual_message_logs (
                id BIGSERIAL PRIMARY KEY,
                order_id VARCHAR(80) NOT NULL,
                sender_id VARCHAR(80) NOT NULL,
                message_body TEXT NOT NULL,
                status VARCHAR(20) NOT NULL,
                api_response TEXT DEFAULT NULL,
                sent_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL
            )'
            : 'CREATE TABLE IF NOT EXISTS manual_message_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id VARCHAR(80) NOT NULL,
                sender_id VARCHAR(80) NOT NULL,
                message_body TEXT NOT NULL,
                status VARCHAR(20) NOT NULL,
                api_response TEXT DEFAULT NULL,
                sent_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->pdo->exec($sql);

        $this->manualTableChecked = true;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
