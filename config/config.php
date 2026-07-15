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
];
