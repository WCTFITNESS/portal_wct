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

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 20,
        ];
        // Nem todo build PHP define PDO::MYSQL_ATTR_* (ex.: alguns XAMPP); ATTR_TIMEOUT acima já cobre.
        if ($this->driver === 'mysql' && defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
            $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 15;
        }

        $this->pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], $options);
    }

    private function buildDsn(array $dbConfig): string
    {
        if ($this->driver === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $dbConfig['host'],
                $dbConfig['port'],
                $dbConfig['name']
            );
            $sslmode = trim((string) ($dbConfig['sslmode'] ?? ''));
            if ($sslmode !== '') {
                $dsn .= ';sslmode=' . $sslmode;
            }

            return $dsn;
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
