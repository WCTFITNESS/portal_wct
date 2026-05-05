<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

class MessageTemplateRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getActiveTemplate(): ?array
    {
        $condition = $this->isPgsql() ? 'is_active = TRUE' : 'is_active = 1';
        $stmt = $this->pdo->query("SELECT * FROM message_templates WHERE {$condition} ORDER BY id DESC LIMIT 1");
        $template = $stmt->fetch();

        return $template ?: null;
    }

    public function saveTemplate(string $title, string $body): void
    {
        $inactiveValue = $this->isPgsql() ? 'FALSE' : '0';
        $activeValue = $this->isPgsql() ? 'TRUE' : '1';
        $this->pdo->exec("UPDATE message_templates SET is_active = {$inactiveValue}");

        $stmt = $this->pdo->prepare(
            'INSERT INTO message_templates (title, body, is_active, created_at, updated_at)
             VALUES (:title, :body, ' . $activeValue . ', NOW(), NOW())'
        );
        $stmt->execute([
            ':title' => $title,
            ':body' => $body,
        ]);
    }

    private function isPgsql(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
