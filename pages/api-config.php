<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$detectedSellerId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['form_type'] ?? '') === 'ml_api') {
            $existing = $app['settingsRepository']->getApiConfig() ?? [];
            $app['settingsRepository']->saveApiConfig([
                'app_id' => trim((string) ($_POST['app_id'] ?? '')),
                'client_secret' => trim((string) ($_POST['client_secret'] ?? '')),
                'redirect_uri' => trim((string) ($_POST['redirect_uri'] ?? '')),
                'seller_id' => trim((string) ($_POST['seller_id'] ?? '')),
                'oauth_code' => trim((string) ($_POST['oauth_code'] ?? '')),
                'lexos_code' => trim((string) ($existing['lexos_code'] ?? '')),
                'lexos_token' => trim((string) ($existing['lexos_token'] ?? '')),
                'lexos_refresh_token' => trim((string) ($existing['lexos_refresh_token'] ?? '')),
                'lexos_integration_key' => trim((string) ($existing['lexos_integration_key'] ?? '')),
                'lexos_integration_header_name' => trim((string) ($existing['lexos_integration_header_name'] ?? '')),
            ]);
            $feedback = 'Configurações da API Mercado Livre salvas.';
        }

        if (($_POST['form_type'] ?? '') === 'lexos_api') {
            $existing = $app['settingsRepository']->getApiConfig() ?? [];
            $app['settingsRepository']->saveApiConfig([
                'app_id' => trim((string) ($existing['app_id'] ?? '')),
                'client_secret' => trim((string) ($existing['client_secret'] ?? '')),
                'redirect_uri' => trim((string) ($existing['redirect_uri'] ?? '')),
                'seller_id' => trim((string) ($existing['seller_id'] ?? '')),
                'oauth_code' => trim((string) ($existing['oauth_code'] ?? '')),
                'lexos_code' => trim((string) ($_POST['lexos_code'] ?? '')),
                'lexos_token' => trim((string) ($_POST['lexos_token'] ?? '')),
                'lexos_refresh_token' => trim((string) ($_POST['lexos_refresh_token'] ?? '')),
                'lexos_integration_key' => trim((string) ($_POST['lexos_integration_key'] ?? '')),
                'lexos_integration_header_name' => trim((string) ($_POST['lexos_integration_header_name'] ?? '')),
            ]);
            $feedback = 'Configurações da API Lexos salvas.';
        }

        if (($_POST['form_type'] ?? '') === 'lexos_token_from_code') {
            $code = trim((string) ($_POST['lexos_code'] ?? ''));
            $result = $app['lexosAuthService']->exchangeCodeForToken($code);
            $feedback = 'Token Lexos gerado e salvo com sucesso.';
            if (($result['refresh_token'] ?? '') === '') {
                $feedback .= ' Atenção: a resposta não retornou refresh token.';
            }
            $apiConfig = $app['settingsRepository']->getApiConfig();
        }

        if (($_POST['form_type'] ?? '') === 'lexos_refresh_token') {
            $refreshToken = trim((string) ($_POST['lexos_refresh_token'] ?? ''));
            $app['lexosAuthService']->refreshLexosToken($refreshToken);
            $feedback = 'Refresh token Lexos executado e tokens salvos.';
            $apiConfig = $app['settingsRepository']->getApiConfig();
        }

        if (($_POST['form_type'] ?? '') === 'token') {
            $app['tokenService']->saveInitialToken([
                'access_token' => trim((string) ($_POST['access_token'] ?? '')),
                'refresh_token' => trim((string) ($_POST['refresh_token'] ?? '')),
                'expires_in' => (int) ($_POST['expires_in'] ?? 21600),
                'token_type' => 'Bearer',
            ]);
            $feedback = 'Token inicial salvo com sucesso.';
        }

        if (($_POST['form_type'] ?? '') === 'refresh') {
            $app['tokenService']->refreshToken();
            $feedback = 'Refresh token executado com sucesso.';
        }

        if (($_POST['form_type'] ?? '') === 'mp_token') {
            $app['mercadopagoSettingsRepository']->saveAccessToken(trim((string) ($_POST['mp_access_token'] ?? '')));
            $feedback = 'Access token do Mercado Pago salvo.';
        }

        if (($_POST['form_type'] ?? '') === 'check-seller-id') {
            $accessToken = $app['tokenService']->getValidAccessToken();
            $result = $app['mercadoLivreClient']->get('/users/me', $accessToken);

            if ($result['status'] < 200 || $result['status'] >= 300) {
                $raw = substr((string) ($result['raw'] ?? ''), 0, 300);
                throw new RuntimeException(
                    'Falha ao validar usuário do token. HTTP: ' . $result['status'] . ($raw !== '' ? ' Resposta: ' . $raw : '')
                );
            }

            $detectedSellerId = (string) ($result['body']['id'] ?? '');
            if ($detectedSellerId === '') {
                throw new RuntimeException('Não foi possível identificar o ID da conta pelo token atual.');
            }

            $savedSellerId = trim((string) (($app['settingsRepository']->getApiConfig()['seller_id'] ?? '')));
            $feedback = 'ID da conta no token: ' . $detectedSellerId . '.';

            if ($savedSellerId !== '' && $savedSellerId !== $detectedSellerId) {
                $feedbackClass = 'err';
                $feedback .= ' O Seller ID salvo é diferente (' . $savedSellerId . '). Atualize para evitar erro 403.';
            } else {
                $feedback .= ' Seller ID está compatível com o token.';
            }
        }
    } catch (Throwable $exception) {
        $feedback = 'Erro: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

$apiConfig = $app['settingsRepository']->getApiConfig();
$token = $app['tokenRepository']->getLatestToken();
$mpRow = $app['mercadopagoSettingsRepository']->getSettings();
$requestLogs = $app['requestLogRepository']->listRecent(10);
?>
<section class="card">
    <h1>Configuração API Mercado Livre</h1>
    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="form_type" value="ml_api">
        <label>App ID</label>
        <input type="text" name="app_id" value="<?= htmlspecialchars((string) ($apiConfig['app_id'] ?? '')) ?>" required>

        <label>Client Secret</label>
        <input type="text" name="client_secret" value="<?= htmlspecialchars((string) ($apiConfig['client_secret'] ?? '')) ?>" required>

        <label>Redirect URI</label>
        <input type="text" name="redirect_uri" value="<?= htmlspecialchars((string) ($apiConfig['redirect_uri'] ?? '')) ?>">

        <label>Seller ID</label>
        <input type="text" name="seller_id" value="<?= htmlspecialchars((string) ($apiConfig['seller_id'] ?? '')) ?>" required>

        <label>Code (Authorization Code)</label>
        <input type="text" name="oauth_code" value="<?= htmlspecialchars((string) ($apiConfig['oauth_code'] ?? '')) ?>">

        <button type="submit">Salvar Configuração ML</button>
    </form>
</section>

<section class="card">
    <h1>Configuração API Lexos</h1>
    <form method="post">
        <input type="hidden" name="form_type" value="lexos_api">

        <label>Lexos Code</label>
        <textarea name="lexos_code" rows="2" placeholder="Cole o code da Lexos"><?= htmlspecialchars((string) ($apiConfig['lexos_code'] ?? '')) ?></textarea>

        <label>Token Lexos</label>
        <textarea name="lexos_token" rows="3" placeholder="Cole o access_token da Lexos"><?= htmlspecialchars((string) ($apiConfig['lexos_token'] ?? '')) ?></textarea>

        <label>Refresh token Lexos</label>
        <textarea name="lexos_refresh_token" rows="3" placeholder="Cole o refresh token da Lexos"><?= htmlspecialchars((string) ($apiConfig['lexos_refresh_token'] ?? '')) ?></textarea>

        <label>Chave segura da integração Lexos (header Chave)</label>
        <textarea name="lexos_integration_key" rows="2" placeholder="Cole a chave segura gerada no Lexos Hub (integração Lexos API)"><?= htmlspecialchars((string) ($apiConfig['lexos_integration_key'] ?? '')) ?></textarea>

        <label>Nome do header da chave (se seu tenant exigir nome específico)</label>
        <input type="text" name="lexos_integration_header_name" placeholder="Ex.: x-api-key ou x-tenant-key" value="<?= htmlspecialchars((string) ($apiConfig['lexos_integration_header_name'] ?? '')) ?>">

        <button type="submit">Salvar Configuração Lexos</button>
    </form>

    <form method="post">
        <input type="hidden" name="form_type" value="lexos_token_from_code">
        <input type="hidden" name="lexos_code" value="<?= htmlspecialchars((string) ($apiConfig['lexos_code'] ?? '')) ?>">
        <button type="submit">Gerar Token Lexos via Code</button>
    </form>

    <form method="post">
        <input type="hidden" name="form_type" value="lexos_refresh_token">
        <input type="hidden" name="lexos_refresh_token" value="<?= htmlspecialchars((string) ($apiConfig['lexos_refresh_token'] ?? '')) ?>">
        <button type="submit">Executar Refresh Token Lexos</button>
    </form>
</section>

<section class="card">
    <h1>Token OAuth</h1>
    <p>Guarde o token atual e o refresh token para renovação automática.</p>
    <form method="post">
        <input type="hidden" name="form_type" value="token">
        <label>Access Token</label>
        <textarea name="access_token" rows="4" required><?= htmlspecialchars((string) ($token['access_token'] ?? '')) ?></textarea>

        <label>Refresh Token</label>
        <textarea name="refresh_token" rows="3" required><?= htmlspecialchars((string) ($token['refresh_token'] ?? '')) ?></textarea>

        <label>Expira em (segundos)</label>
        <input type="number" name="expires_in" value="<?= htmlspecialchars((string) ($token['expires_in'] ?? '21600')) ?>" min="60" required>

        <button type="submit">Salvar Token</button>
    </form>

    <form method="post">
        <input type="hidden" name="form_type" value="refresh">
        <button type="submit">Executar Refresh Agora</button>
    </form>

    <form method="post">
        <input type="hidden" name="form_type" value="check-seller-id">
        <button type="submit">Verificar ID Correto do Token</button>
    </form>

    <?php if ($token): ?>
        <p>Expira em: <strong><?= htmlspecialchars((string) $token['expires_at']) ?></strong></p>
    <?php endif; ?>
    <?php if ($detectedSellerId): ?>
        <p>ID detectado no token atual: <strong><?= htmlspecialchars($detectedSellerId) ?></strong></p>
    <?php endif; ?>
</section>

<section class="card">
    <h1>Token Mercado Pago (Repasse MP)</h1>
    <p>Token usado nas rotinas da tela <strong>Repasse MP</strong>.</p>
    <form method="post">
        <input type="hidden" name="form_type" value="mp_token">
        <label>Access Token (Mercado Pago)</label>
        <textarea name="mp_access_token" rows="3" placeholder="APP_USR-..."><?= htmlspecialchars((string) ($mpRow['access_token'] ?? '')) ?></textarea>
        <button type="submit">Salvar token MP</button>
    </form>
</section>

<section class="card">
    <h1>Últimos 10 requests (API Mercado Livre)</h1>
    <table>
        <thead>
        <tr>
            <th>Método</th>
            <th>Endpoint</th>
            <th>HTTP</th>
            <th>Resultado</th>
            <th>Data</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$requestLogs): ?>
            <tr><td colspan="5">Nenhum request registrado ainda.</td></tr>
        <?php endif; ?>
        <?php foreach ($requestLogs as $requestLog): ?>
            <tr>
                <td><?= htmlspecialchars((string) $requestLog['method']) ?></td>
                <td><?= htmlspecialchars((string) $requestLog['path']) ?></td>
                <td><?= htmlspecialchars((string) ($requestLog['http_status'] ?? '-')) ?></td>
                <td>
                    <?php
                    $resultText = (string) ($requestLog['error_message'] ?: $requestLog['response_body'] ?: '-');
                    $resultText = substr($resultText, 0, 140);
                    ?>
                    <?= htmlspecialchars($resultText) ?>
                </td>
                <td><?= htmlspecialchars((string) $requestLog['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
