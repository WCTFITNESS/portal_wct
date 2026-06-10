<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Conexão PostgreSQL com o banco do Tracking WCT (lexos_tokens).
 */
final class TrackingDatabase
{
    private ?PDO $pdo = null;

    public function __construct(private string $databaseUrl)
    {
    }

    public function isConfigured(): bool
    {
        return trim($this->databaseUrl) !== '';
    }

    public function pdo(): PDO
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('URL do banco Tracking não configurada.');
        }

        if ($this->pdo === null) {
            $this->pdo = self::connect($this->databaseUrl);
        }

        return $this->pdo;
    }

    public static function connect(string $databaseUrl): PDO
    {
        $databaseUrl = trim($databaseUrl);
        if ($databaseUrl === '') {
            throw new RuntimeException('URL do banco Tracking vazia.');
        }

        $cfg = self::parseUrl($databaseUrl);

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['sslmode'] ?? 'require'
        );

        return new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 15,
        ]);
    }

    /**
     * @return array{driver: string, host: string, port: int, name: string, user: string, pass: string}
     */
    public static function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new RuntimeException('DATABASE_URL do Tracking inválida.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'postgres' && $scheme !== 'postgresql') {
            throw new RuntimeException('A URL do Tracking deve ser PostgreSQL (postgresql://...).');
        }

        $name = ltrim((string) ($parts['path'] ?? ''), '/');
        if ($name === '') {
            throw new RuntimeException('Nome do banco ausente na URL do Tracking.');
        }

        $sslmode = 'require';
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (is_array($query) && isset($query['sslmode']) && is_string($query['sslmode']) && $query['sslmode'] !== '') {
                $sslmode = $query['sslmode'];
            }
        }

        return [
            'driver' => 'pgsql',
            'host' => (string) $parts['host'],
            'port' => (int) ($parts['port'] ?? 5432),
            'name' => $name,
            'user' => (string) ($parts['user'] ?? ''),
            'pass' => (string) ($parts['pass'] ?? ''),
            'sslmode' => $sslmode,
        ];
    }
}
