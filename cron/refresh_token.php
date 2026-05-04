<?php

declare(strict_types=1);

try {
    /** @var array $app */
    $app = require __DIR__ . '/../app.php';
    $app['tokenService']->refreshToken();

    echo date('Y-m-d H:i:s') . " - Refresh token atualizado com sucesso." . PHP_EOL;
} catch (Throwable $exception) {
    echo date('Y-m-d H:i:s') . ' - Erro no refresh token: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
