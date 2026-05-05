<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

class Database
{
    private PDO $pdo;
    private string $driver;

    public function __construct(array $dbConfig)
    {
        $this->driver = strtolower((string) ($dbConfig['driver'] ?? 'mysql'));
        $dsn = $this->buildDsn($dbConfig);

        $this->pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function buildDsn(array $dbConfig): string
    {
        if ($this->driver === 'pgsql') {
            return sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['name']
            );
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['name'],
            $dbConfig['charset'] ?? 'utf8mb4'
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return $this->driver;
    }
}
