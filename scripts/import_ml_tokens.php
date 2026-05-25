<?php

declare(strict_types=1);

/**
 * Importa credenciais/tokens ML de docs/ml_tokens.json para o banco local.
 * Uso: php scripts/import_ml_tokens.php [caminho_json]
 */

$jsonPath = $argv[1] ?? (__DIR__ . '/../docs/ml_tokens.json');
if (!is_file($jsonPath)) {
    fwrite(STDERR, "Arquivo não encontrado: {$jsonPath}\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($jsonPath), true);
if (!is_array($data)) {
    fwrite(STDERR, "JSON inválido.\n");
    exit(1);
}

$app = require __DIR__ . '/../app.php';

$appId = trim((string) ($data['app_id'] ?? ''));
$clientSecret = trim((string) ($data['client_secret'] ?? ''));
$redirectUri = trim((string) ($data['redirect_uri'] ?? ''));
$sellerId = trim((string) ($data['user_id'] ?? ($data['seller_id'] ?? '')));
$oauthCode = trim((string) ($data['oauth_code'] ?? ''));
$accessToken = trim((string) ($data['access_token'] ?? ''));
$refreshToken = trim((string) ($data['refresh_token'] ?? ''));
$expiresIn = (int) ($data['expires_in'] ?? 21600);

if ($appId === '' || $clientSecret === '') {
    fwrite(STDERR, "app_id e client_secret são obrigatórios no JSON.\n");
    exit(1);
}

$app['settingsRepository']->saveApiConfig([
    'app_id' => $appId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'seller_id' => $sellerId,
    'oauth_code' => $oauthCode,
    'lexos_code' => '',
    'lexos_token' => '',
    'lexos_refresh_token' => '',
    'lexos_integration_key' => '',
    'lexos_integration_header_name' => '',
]);

if ($accessToken !== '' && $refreshToken !== '') {
    $app['tokenService']->saveInitialToken([
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'expires_in' => $expiresIn,
        'token_type' => 'Bearer',
    ]);
}

echo "Importado: app_id={$appId}, seller_id={$sellerId}\n";

try {
    $valid = $app['tokenService']->getValidAccessToken();
    $me = $app['mercadoLivreClient']->get('/users/me', $valid);
    $status = (int) $me['status'];
    $id = $me['body']['id'] ?? '?';
    echo "Teste /users/me: HTTP {$status}, user_id={$id}\n";
    exit($status >= 200 && $status < 300 ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Teste falhou: ' . $e->getMessage() . "\n");
    exit(2);
}
