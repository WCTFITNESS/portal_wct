<?php

/**
 * Config usada apenas na imagem Docker (sobrescreve config/config.php no build).
 * URLs públicas via variáveis de ambiente.
 *
 * Banco no Render: o hostname "mysql" só existe no docker-compose local.
 * No Render defina PORTAL_DB_HOST (host real do MySQL) ou PORTAL_DATABASE_URL.
 */

/**
 * @return array{host:string,port:int,name:string,user:string,pass:string,charset:string}|null
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

$urlRaw = getenv('PORTAL_DATABASE_URL');
$url = is_string($urlRaw) ? trim($urlRaw) : '';
$db = null;
if ($url !== '') {
    $db = portal_wct_parse_mysql_url($url);
}

if ($db === null) {
    $db = [
        'host' => getenv('PORTAL_DB_HOST') ?: 'mysql',
        'port' => (int) (getenv('PORTAL_DB_PORT') ?: '3306'),
        'name' => getenv('PORTAL_DB_NAME') ?: 'portal_wct',
        'user' => getenv('PORTAL_DB_USER') ?: 'portal',
        'pass' => getenv('PORTAL_DB_PASS') !== false ? (string) getenv('PORTAL_DB_PASS') : '',
        'charset' => 'utf8mb4',
    ];
}

$baseRaw = getenv('PORTAL_BASE_URL');
$baseUrl = $baseRaw === false ? '/portal_wct' : rtrim((string) $baseRaw, '/');

$trackingRaw = getenv('TRACKING_PUBLIC_URL');
$trackingUrl = $trackingRaw !== false && $trackingRaw !== ''
    ? (string) $trackingRaw
    : 'http://tracking.wct.local:8088/admin/dashboard';

return [
    'db' => $db,
    'app' => [
        'timezone' => getenv('PORTAL_TZ') ?: 'America/Sao_Paulo',
        'base_url' => $baseUrl,
        'tracking_wct_url' => $trackingUrl,
    ],
];
