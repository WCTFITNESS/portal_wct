<?php

declare(strict_types=1);

/**
 * Testa renovação do Token Hub e endpoint de Produtos (sem expor tokens).
 * Uso: php tools/test_lexos_hub_products.php
 */

try {
    /** @var array $app */
    $app = require __DIR__ . '/../app.php';

    $creds = $app['lexosCredentialsService'];
    $hasRefresh = $creds->getHubRefreshToken() !== '';
    $hasAccess = $creds->getHubAccessToken() !== '';

    echo 'Hub refresh configurado: ' . ($hasRefresh ? 'sim' : 'nao') . PHP_EOL;
    echo 'Hub access presente: ' . ($hasAccess ? 'sim' : 'nao') . PHP_EOL;

    $sessionOk = $app['lexosHubSessionService']->maintainHubSession();
    echo 'Sessao Hub valida: ' . ($sessionOk ? 'sim' : 'nao') . PHP_EOL;

    if (!$sessionOk) {
        exit(1);
    }

    $probe = $app['lexosHubApiClient']->probeHubApi();
    echo 'Probe CurvaAbc OK: ' . (($probe['hub_ok'] ?? false) ? 'sim' : 'nao') . PHP_EOL;
    if (!($probe['hub_ok'] ?? false)) {
        echo 'Erro probe: ' . (string) ($probe['hub_error'] ?? 'desconhecido') . PHP_EOL;
        exit(1);
    }

    $today = (new DateTimeImmutable('now'))->format('Y-m-d');
    $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
    $products = $app['lexosDashboardService']->getProducts($monthStart, $today, '', 5, 0);
    $count = (int) ($products['count'] ?? 0);
    $items = is_array($products['items'] ?? null) ? count($products['items']) : 0;

    echo 'Produtos retornados: ' . $items . ' (total filtrado: ' . $count . ')' . PHP_EOL;
    exit($items > 0 ? 0 : 1);
} catch (Throwable $exception) {
    echo 'Erro: ' . $exception->getMessage() . PHP_EOL;
    exit(1);
}
