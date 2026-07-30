<?php

return [
    'db' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'portal_wct',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'timezone' => 'America/Sao_Paulo',
        // Pasta local no XAMPP: htdocs/ml-portal (atalho portal_wct aponta para ela).
        'base_url' => '/ml-portal',
        /** URL do Tracking WCT (Node, ex.: http://localhost:3001/admin/dashboard) */
        'tracking_wct_url' => 'http://localhost:3001/admin/dashboard',
        /** WCT Code Node (local: suba `npm start` em wct-code/ na porta 3001) */
        'wct_code_url' => 'http://127.0.0.1:3001/wct-code-app',
    ],
    /** E-mail (Tasks). No Render use PORTAL_SMTP_* / PORTAL_MAIL_* */
    'mail' => [
        'from' => getenv('PORTAL_MAIL_FROM') ?: 'noreply@portal-wct.local',
        'from_name' => getenv('PORTAL_MAIL_FROM_NAME') ?: 'Portal WCT — Tasks',
        'smtp_host' => getenv('PORTAL_SMTP_HOST') ?: '',
        'smtp_port' => (int) (getenv('PORTAL_SMTP_PORT') ?: 587),
        'smtp_user' => getenv('PORTAL_SMTP_USER') ?: '',
        'smtp_pass' => getenv('PORTAL_SMTP_PASS') !== false ? (string) getenv('PORTAL_SMTP_PASS') : '',
        'smtp_secure' => getenv('PORTAL_SMTP_SECURE') ?: 'tls',
    ],
];
