<?php

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'portal_wct',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'timezone' => 'America/Sao_Paulo',
        'base_url' => '/portal_wct',
        /** URL do Tracking WCT (Node, ex.: http://localhost:3001/admin/dashboard) */
        'tracking_wct_url' => 'http://localhost:3001/admin/dashboard',
    ],
];
