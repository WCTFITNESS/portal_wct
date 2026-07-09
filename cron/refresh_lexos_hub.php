<?php

declare(strict_types=1);

try {
    /** @var array $app */
    $app = require __DIR__ . '/../app.php';

    $ok = $app['lexosHubSessionService']->maintainHubSession();
    if (!$ok) {
        throw new RuntimeException('Refresh Token Hub ausente ou inválido. Configure LEXOS_HUB_REFRESH_TOKEN no Render ou em Configuração API → Lexos.');
    }

    echo date('Y-m-d H:i:s') . ' - Token Hub Lexos renovado/validado com sucesso.' . PHP_EOL;
} catch (Throwable $exception) {
    echo date('Y-m-d H:i:s') . ' - Erro no refresh Token Hub: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
