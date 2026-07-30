<?php

declare(strict_types=1);

/**
 * Config usada apenas na imagem Docker (sobrescreve config/config.php no build).
 *
 * Na subida do container, deploy/render/bake-db-runtime.php grava config/db-runtime.php
 * (PHP CLI herda sempre as env vars do Docker/Render; mod_php nem sempre via getenv).
 * Sem esse arquivo — ex.: servidor local sem entrypoint — lê só as variáveis do processo atual.
 */

require __DIR__ . '/../deploy/render/mysql_env.php';

$dbFile = __DIR__ . '/db-runtime.php';
$db = is_readable($dbFile)
    ? require $dbFile
    : portal_wct_db_from_environment();

$baseRaw = getenv('PORTAL_BASE_URL');
$baseUrl = $baseRaw === false ? '/portal_wct' : rtrim((string) $baseRaw, '/');
// PORTAL_BASE_URL=/ vira '' apos rtrim; manter '/' evita layouts estranhos.
if ($baseUrl === '') {
    $baseUrl = '/';
}

$trackingRaw = getenv('TRACKING_PUBLIC_URL');
$trackingUrl = $trackingRaw !== false && $trackingRaw !== ''
    ? (string) $trackingRaw
    : 'http://tracking.wct.local:8088/admin/dashboard';

$wctCodeUrl = $baseUrl === '/' ? '/wct-code-app' : rtrim($baseUrl, '/') . '/wct-code-app';

return [
    'db' => $db,
    'app' => [
        'timezone' => getenv('PORTAL_TZ') ?: 'America/Sao_Paulo',
        'base_url' => $baseUrl,
        'tracking_wct_url' => $trackingUrl,
        'wct_code_url' => $wctCodeUrl,
    ],
    'mail' => [
        'from' => getenv('PORTAL_MAIL_FROM') ?: 'noreply@portal-wct.local',
        'from_name' => getenv('PORTAL_MAIL_FROM_NAME') ?: 'Portal WCT — Tasks',
        'smtp_host' => getenv('PORTAL_SMTP_HOST') ?: '',
        'smtp_port' => (int) (getenv('PORTAL_SMTP_PORT') ?: 587),
        'smtp_user' => getenv('PORTAL_SMTP_USER') ?: '',
        'smtp_pass' => getenv('PORTAL_SMTP_PASS') !== false ? (string) getenv('PORTAL_SMTP_PASS') : '',
        'smtp_secure' => getenv('PORTAL_SMTP_SECURE') ?: 'tls',
    ],
    'ssw' => [
        'cnpj' => getenv('PORTAL_SSW_CNPJ') ?: '17751890000176',
        'senha' => getenv('PORTAL_SSW_SENHA') !== false ? (string) getenv('PORTAL_SSW_SENHA') : '',
    ],
];
