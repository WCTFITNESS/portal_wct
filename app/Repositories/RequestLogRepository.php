<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class RequestLogRepository
{
    private bool $tableChecked = false;

    public function __construct(private PDO $pdo)
    {
    }

    public function register(
        string $method,
        string $path,
        ?int $httpStatus,
        ?string $requestPayload,
        ?string $responseBody,
        ?string $errorMessage = null
    ): void {
        $this->ensureTableExists();
        $safeMethod = $this->truncate($method, 10);
        $safePath = $this->truncate($path, 255);

        $stmt = $this->pdo->prepare(
            'INSERT INTO request_logs
             (method, path, http_status, request_payload, response_body, error_message, created_at)
             VALUES (:method, :path, :http_status, :request_payload, :response_body, :error_message, NOW())'
        );

        $stmt->execute([
            ':method' => strtoupper($safeMethod),
            ':path' => $safePath,
            ':http_status' => $httpStatus,
            ':request_payload' => $requestPayload,
            ':response_body' => $responseBody,
            ':error_message' => $errorMessage,
        ]);
    }

    public function listRecent(int $limit = 10): array
    {
        $this->ensureTableExists();

        $stmt = $this->pdo->prepare('SELECT * FROM request_logs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function ensureTableExists(): void
    {
        if ($this->tableChecked) {
            return;
        }

        $sql = $this->isPgsql()
            ? 'CREATE TABLE IF NOT EXISTS request_logs (
                id BIGSERIAL PRIMARY KEY,
                method VARCHAR(10) NOT NULL,
                path VARCHAR(255) NOT NULL,
                http_status INT DEFAULT NULL,
                request_payload TEXT DEFAULT NULL,
                response_body TEXT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL
            )'
            : 'CREATE TABLE IF NOT EXISTS request_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                method VARCHAR(10) NOT NULL,
                path VARCHAR(255) NOT NULL,
                http_status INT DEFAULT NULL,
                request_payload TEXT DEFAULT NULL,
                response_body TEXT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL
            )';
        $this->pdo->exec($sql);

        $this->tableChecked = true;
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }

    private function truncate(string $value, int $max): string
    {
        if ($max < 1) {
            return '';
        }
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        if ($max <= 3) {
            return mb_substr($value, 0, $max);
        }

        return mb_substr($value, 0, $max - 3) . '...';
    }
}
