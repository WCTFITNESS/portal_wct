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
        $stmt = $this->pdo->query('SELECT * FROM message_templates WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
        $template = $stmt->fetch();

        return $template ?: null;
    }

    public function saveTemplate(string $title, string $body): void
    {
        $this->pdo->exec('UPDATE message_templates SET is_active = 0');

        $stmt = $this->pdo->prepare(
            'INSERT INTO message_templates (title, body, is_active, created_at, updated_at)
             VALUES (:title, :body, 1, NOW(), NOW())'
        );
        $stmt->execute([
            ':title' => $title,
            ':body' => $body,
        ]);
    }
}
