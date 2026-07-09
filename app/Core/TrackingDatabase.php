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
        if ($cfg['user'] === '' || $cfg['pass'] === '') {
            throw new RuntimeException(
                'URL do Tracking sem usuário ou senha. Cole a URL completa do Render '
                . '(postgresql://usuario:senha@host/banco) ou configure TRACKING_DATABASE_URL no servidor.'
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['sslmode'] ?? 'require'
        );

        try {
            return new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 15,
            ]);
        } catch (\PDOException $e) {
            throw new RuntimeException(
                'Falha ao conectar no banco Tracking: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public static function hasValidCredentials(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        try {
            $cfg = self::parseUrl($url);

            return $cfg['user'] !== '' && $cfg['pass'] !== '';
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @return array{driver: string, host: string, port: int, name: string, user: string, pass: string, sslmode?: string}
     */
    public static function parseUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('DATABASE_URL do Tracking vazia.');
        }

        if (!preg_match('#^postgres(?:ql)?://#i', $url)) {
            throw new RuntimeException('A URL do Tracking deve ser PostgreSQL (postgresql://...).');
        }

        $withoutScheme = (string) preg_replace('#^postgres(?:ql)?://#i', '', $url, 1);
        $query = '';
        $queryPos = strpos($withoutScheme, '?');
        if ($queryPos !== false) {
            $query = substr($withoutScheme, $queryPos + 1);
            $withoutScheme = substr($withoutScheme, 0, $queryPos);
        }

        $path = '';
        $slashPos = strpos($withoutScheme, '/');
        if ($slashPos !== false) {
            $path = substr($withoutScheme, $slashPos + 1);
            $withoutScheme = substr($withoutScheme, 0, $slashPos);
        }

        $name = trim($path);
        if ($name === '') {
            throw new RuntimeException('Nome do banco ausente na URL do Tracking.');
        }

        $user = '';
        $pass = '';
        $hostPart = $withoutScheme;
        $atPos = strrpos($withoutScheme, '@');
        if ($atPos !== false) {
            $credPart = substr($withoutScheme, 0, $atPos);
            $hostPart = substr($withoutScheme, $atPos + 1);
            $colonPos = strpos($credPart, ':');
            if ($colonPos !== false) {
                $user = substr($credPart, 0, $colonPos);
                $pass = substr($credPart, $colonPos + 1);
            } else {
                $user = $credPart;
            }
        }

        $host = $hostPart;
        $port = 5432;
        $colonPos = strrpos($hostPart, ':');
        if ($colonPos !== false) {
            $maybePort = substr($hostPart, $colonPos + 1);
            if ($maybePort !== '' && ctype_digit($maybePort)) {
                $host = substr($hostPart, 0, $colonPos);
                $port = (int) $maybePort;
            }
        }

        if ($host === '') {
            throw new RuntimeException('Host ausente na URL do Tracking.');
        }

        $sslmode = 'require';
        if ($query !== '') {
            parse_str($query, $queryParams);
            if (is_array($queryParams) && isset($queryParams['sslmode']) && is_string($queryParams['sslmode']) && $queryParams['sslmode'] !== '') {
                $sslmode = $queryParams['sslmode'];
            }
        }

        return [
            'driver' => 'pgsql',
            'host' => rawurldecode($host),
            'port' => $port,
            'name' => rawurldecode($name),
            'user' => rawurldecode($user),
            'pass' => rawurldecode($pass),
            'sslmode' => $sslmode,
        ];
    }
}
