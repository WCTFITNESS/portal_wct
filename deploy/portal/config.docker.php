<?php

/**
 * Config usada apenas na imagem Docker (sobrescreve config/config.php no build).
 * Ajuste URLs publicas via variaveis de ambiente do compose.
 */

return [
    'db' => [
        'host' => getenv('PORTAL_DB_HOST') ?: 'mysql',
        'port' => (int) (getenv('PORTAL_DB_PORT') ?: '3306'),
        'name' => getenv('PORTAL_DB_NAME') ?: 'portal_wct',
        'user' => getenv('PORTAL_DB_USER') ?: 'portal',
        'pass' => getenv('PORTAL_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'timezone' => getenv('PORTAL_TZ') ?: 'America/Sao_Paulo',
        'base_url' => getenv('PORTAL_BASE_URL') ?: '/portal_wct',
        'tracking_wct_url' => getenv('TRACKING_PUBLIC_URL') ?: 'http://tracking.wct.local:8088/admin/dashboard',
    ],
];
