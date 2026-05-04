<?php

declare(strict_types=1);

/**
 * Leitura das credenciais MySQL das variáveis do processo (Docker/Render).
 * Usado pela config Docker e pelo script CLI bake-db-runtime.php.
 */

function portal_wct_parse_mysql_url(string $url): ?array
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['mysql', 'mysqli'], true)) {
        return null;
    }
    $pass = array_key_exists('pass', $parts) ? rawurldecode((string) $parts['pass']) : '';
    $user = array_key_exists('user', $parts) ? rawurldecode((string) $parts['user']) : 'portal';
    $path = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
    $name = $path !== '' ? explode('?', $path, 2)[0] : 'portal_wct';
    $port = isset($parts['port']) ? (int) $parts['port'] : 3306;

    return [
        'host' => $parts['host'],
        'port' => $port,
        'name' => $name !== '' ? $name : 'portal_wct',
        'user' => $user,
        'pass' => $pass,
        'charset' => 'utf8mb4',
    ];
}

/**
 * @return array{host:string,port:int,name:string,user:string,pass:string,charset:string}
 */
function portal_wct_db_from_environment(): array
{
    $urlRaw = getenv('PORTAL_DATABASE_URL');
    $url = is_string($urlRaw) ? trim($urlRaw) : '';
    if ($url !== '') {
        $db = portal_wct_parse_mysql_url($url);
        if ($db !== null) {
            return $db;
        }
    }

    return [
        'host' => getenv('PORTAL_DB_HOST') ?: 'mysql',
        'port' => (int) (getenv('PORTAL_DB_PORT') ?: '3306'),
        'name' => getenv('PORTAL_DB_NAME') ?: 'portal_wct',
        'user' => getenv('PORTAL_DB_USER') ?: 'portal',
        'pass' => getenv('PORTAL_DB_PASS') !== false ? (string) getenv('PORTAL_DB_PASS') : '',
        'charset' => 'utf8mb4',
    ];
}
