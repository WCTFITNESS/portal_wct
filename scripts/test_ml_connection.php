<?php

declare(strict_types=1);

$app = require __DIR__ . '/../app.php';

try {
    $app['tokenService']->refreshToken();
    echo "Refresh: OK\n";
} catch (Throwable $e) {
    echo "Refresh: FALHOU - " . $e->getMessage() . "\n";
    exit(2);
}

try {
    $token = $app['tokenService']->getValidAccessToken();
    $me = $app['mercadoLivreClient']->get('/users/me', $token);
    echo '/users/me HTTP: ' . $me['status'] . "\n";
    echo 'user_id: ' . ($me['body']['id'] ?? '?') . "\n";
    if (($me['status'] ?? 0) < 200 || ($me['status'] ?? 0) >= 300) {
        echo substr((string) ($me['raw'] ?? ''), 0, 400) . "\n";
        exit(2);
    }
} catch (Throwable $e) {
    echo '/users/me: FALHOU - ' . $e->getMessage() . "\n";
    exit(2);
}
