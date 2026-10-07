<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Login da API Rock.IT (notas do Fulfillment Casas Bahia). Senha e token ficam cifrados.
 */
class RockitSettingsRepository
{
    private const COLUMNS = [
        'email', 'password_blob', 'token_blob', 'token_expires_at', 'id_company', 'companies_json', 'last_status',
    ];

    private ?bool $tableReady = null;

    public function __construct(private PDO $pdo)
    {
    }

    public function get(): ?array
    {
        $this->ensureTable();
        $row = $this->pdo->query('SELECT * FROM rockit_settings ORDER BY id ASC LIMIT 1')->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $fields */
    public function save(array $fields): void
    {
        $this->ensureTable();
        $fields = array_intersect_key($fields, array_flip(self::COLUMNS));
        if ($fields === []) {
            return;
        }
        $params = [];
        foreach ($fields as $col => $value) {
            $params[':' . $col] = $value;
        }

        $existing = $this->get();
        if ($existing === null) {
            $this->pdo->prepare(
                'INSERT INTO rockit_settings (' . implode(', ', array_keys($fields)) . ', updated_at)
                 VALUES (' . implode(', ', array_keys($params)) . ', NOW())'
            )->execute($params);

            return;
        }

        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, array_keys($fields)));
        $params[':id'] = $existing['id'];
        $this->pdo->prepare('UPDATE rockit_settings SET ' . $sets . ', updated_at = NOW() WHERE id = :id')->execute($params);
    }

    public function clear(): void
    {
        $this->ensureTable();
        $this->pdo->exec('DELETE FROM rockit_settings');
    }

    private function ensureTable(): void
    {
        if ($this->tableReady === true) {
            return;
        }
        $pg = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $id = $pg ? 'id BIGSERIAL PRIMARY KEY' : 'id INT AUTO_INCREMENT PRIMARY KEY';
        $ts = $pg ? 'TIMESTAMP' : 'DATETIME';
        $engine = $pg ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS rockit_settings (
                {$id},
                email VARCHAR(190) NOT NULL DEFAULT '',
                password_blob TEXT NULL,
                token_blob TEXT NULL,
                token_expires_at {$ts} NULL,
                id_company VARCHAR(20) NULL,
                companies_json TEXT NULL,
                last_status VARCHAR(500) NULL,
                updated_at {$ts} NOT NULL
            ){$engine}"
        );
        $this->tableReady = true;
    }
}
