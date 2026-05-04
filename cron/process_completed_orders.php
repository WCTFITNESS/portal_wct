<?php

declare(strict_types=1);

try {
    /** @var array $app */
    $app = require __DIR__ . '/../app.php';
    $processed = $app['orderService']->processCompletedOrders();
    echo date('Y-m-d H:i:s') . " - Pedidos processados: {$processed}" . PHP_EOL;
} catch (Throwable $exception) {
    echo date('Y-m-d H:i:s') . ' - Erro: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
