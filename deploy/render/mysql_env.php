<?php

declare(strict_types=1);

/**
 * Leitura das credenciais do banco nas variáveis do processo (Docker/Render).
 * Suporta MySQL e PostgreSQL.
 */

function portal_wct_parse_database_url(string $url): ?array
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $driver = match ($scheme) {
        'mysql', 'mysqli' => 'mysql',
        'pgsql', 'postgres', 'postgresql' => 'pgsql',
        default => null,
    };
    if ($driver === null) {
        return null;
    }
    $pass = array_key_exists('pass', $parts) ? rawurldecode((string) $parts['pass']) : '';
    $user = array_key_exists('user', $parts) ? rawurldecode((string) $parts['user']) : ($driver === 'pgsql' ? 'postgres' : 'portal');
    $path = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
    $name = $path !== '' ? explode('?', $path, 2)[0] : ($driver === 'pgsql' ? 'postgres' : 'portal_wct');
    $port = isset($parts['port']) ? (int) $parts['port'] : ($driver === 'pgsql' ? 5432 : 3306);

    return [
        'driver' => $driver,
        'host' => $parts['host'],
        'port' => $port,
        'name' => $name !== '' ? $name : ($driver === 'pgsql' ? 'postgres' : 'portal_wct'),
        'user' => $user,
        'pass' => $pass,
        'charset' => 'utf8mb4',
    ];
}

/**
 * @return array{driver:string,host:string,port:int,name:string,user:string,pass:string,charset:string}
 */
function portal_wct_db_from_environment(): array
{
    $urlRaw = getenv('PORTAL_DATABASE_URL');
    $url = is_string($urlRaw) ? trim($urlRaw) : '';
    if ($url !== '') {
        $db = portal_wct_parse_database_url($url);
        if ($db !== null) {
            return $db;
        }
    }

    $driverRaw = strtolower((string) (getenv('PORTAL_DB_DRIVER') ?: ''));
    $driver = in_array($driverRaw, ['mysql', 'pgsql'], true)
        ? $driverRaw
        : 'mysql';

    $defaultPort = $driver === 'pgsql' ? '5432' : '3306';
    $defaultUser = $driver === 'pgsql' ? 'postgres' : 'portal';
    $defaultName = $driver === 'pgsql' ? 'postgres' : 'portal_wct';
    $defaultHost = $driver === 'pgsql' ? 'postgres' : 'mysql';

    return [
        'driver' => $driver,
        'host' => getenv('PORTAL_DB_HOST') ?: $defaultHost,
        'port' => (int) (getenv('PORTAL_DB_PORT') ?: $defaultPort),
        'name' => getenv('PORTAL_DB_NAME') ?: $defaultName,
        'user' => getenv('PORTAL_DB_USER') ?: $defaultUser,
        'pass' => getenv('PORTAL_DB_PASS') !== false ? (string) getenv('PORTAL_DB_PASS') : '',
        'charset' => 'utf8mb4',
    ];
}
