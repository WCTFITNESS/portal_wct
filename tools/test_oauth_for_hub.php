<?php

declare(strict_types=1);

/**
 * Testa se o refresh OAuth (Tracking) gera access válido para Produtos Hub.
 * Não imprime tokens.
 */

try {
    /** @var array $app */
    $app = require __DIR__ . '/../app.php';

    $creds = $app['lexosCredentialsService']->resolve();
    $oauthRefresh = trim((string) ($creds['refresh_token'] ?? ''));
    if ($oauthRefresh === '') {
        echo 'oauth_refresh=nao' . PHP_EOL;
        exit(1);
    }

    echo 'oauth_refresh=sim' . PHP_EOL;

    $result = $app['lexosAuthService']->refreshLexosToken($oauthRefresh);
    $access = trim((string) ($result['access_token'] ?? ''));
    echo 'oauth_access_obtido=' . ($access !== '' ? 'sim' : 'nao') . PHP_EOL;

    if ($access === '') {
        exit(1);
    }

    $probeOk = $app['lexosHubSessionService']->isHubAccessValid($access);
    echo 'probe_produtos_ok=' . ($probeOk ? 'sim' : 'nao') . PHP_EOL;
    exit($probeOk ? 0 : 1);
} catch (Throwable $e) {
    echo 'erro=' . $e->getMessage() . PHP_EOL;
    exit(1);
}
