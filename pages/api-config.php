<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$detectedSellerId = null;
$mlAuthUrl = null;

$apiTab = trim((string) ($_POST['api_tab'] ?? $_GET['api_tab'] ?? 'ml'));
if (!in_array($apiTab, ['ml', 'lexos', 'mp'], true)) {
    $apiTab = 'ml';
}

$lexosFormTypes = ['lexos_api', 'lexos_token_from_code', 'lexos_refresh_token', 'lexos_tracking_test', 'lexos_sync_tracking', 'lexos_import_tracking_key'];
$mpFormTypes = ['mp_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTab = trim((string) ($_POST['api_tab'] ?? ''));
    if ($postedTab !== '') {
        $apiTab = $postedTab;
    }
    $formType = (string) ($_POST['form_type'] ?? '');
    if (in_array($formType, $lexosFormTypes, true)) {
        $apiTab = 'lexos';
    } elseif (in_array($formType, $mpFormTypes, true)) {
        $apiTab = 'mp';
    } elseif ($formType !== '') {
        $apiTab = 'ml';
    }

    try {
        if ($formType === 'ml_api') {
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
                'tracking_database_url' => trim((string) ($existing['tracking_database_url'] ?? '')),
                'lexos_credentials_mode' => trim((string) ($existing['lexos_credentials_mode'] ?? 'auto')),
            ]);
            $feedback = 'Configurações da API Mercado Livre salvas.';
        }

        if ($formType === 'lexos_api') {
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
                'tracking_database_url' => trim((string) ($_POST['tracking_database_url'] ?? '')),
                'lexos_credentials_mode' => trim((string) ($_POST['lexos_credentials_mode'] ?? 'auto')),
            ]);
            $feedback = 'Configurações da API Lexos salvas.';
        }

        if ($formType === 'lexos_tracking_test') {
            $testUrl = trim((string) ($_POST['tracking_database_url'] ?? ''));
            if ($testUrl === '') {
                $existing = $app['settingsRepository']->getApiConfig() ?? [];
                $testUrl = trim((string) ($existing['tracking_database_url'] ?? ''));
            }
            if ($testUrl === '') {
                $env = getenv('TRACKING_DATABASE_URL');
                $testUrl = is_string($env) ? trim($env) : '';
            }
            $repo = new \App\Repositories\TrackingLexosTokenRepository(
                new \App\Core\TrackingDatabase($testUrl)
            );
            $test = $repo->testConnection();
            if ($test['ok']) {
                $st = $repo->getPublicStatus($test['row']);
                $feedback = 'Conexão com o banco Tracking OK.';
                if ($st['has_row']) {
                    $feedback .= ' Token: ' . ($st['has_token'] ? 'sim (' . $st['token_preview'] . ')' : 'não');
                    $feedback .= '; Chave: ' . ($st['has_chave'] ? 'sim (' . $st['chave_preview'] . ')' : 'não');
                    if ($st['atualizado_em'] !== '') {
                        $feedback .= '; atualizado em ' . $st['atualizado_em'];
                    }
                } else {
                    $feedback .= ' Tabela lexos_tokens sem registros.';
                }
            } else {
                throw new RuntimeException('Falha ao conectar no Tracking: ' . $test['error']);
            }
        }

        if ($formType === 'lexos_token_from_code') {
            $code = trim((string) ($_POST['lexos_code'] ?? ''));
            $result = $app['lexosAuthService']->exchangeCodeForToken($code);
            $feedback = 'Token Lexos gerado e salvo com sucesso.';
            if (($result['refresh_token'] ?? '') === '') {
                $feedback .= ' Atenção: a resposta não retornou refresh token.';
            }
            $apiConfig = $app['settingsRepository']->getApiConfig();
        }

        if ($formType === 'lexos_refresh_token') {
            $refreshToken = trim((string) ($_POST['lexos_refresh_token'] ?? ''));
            $app['lexosAuthService']->refreshLexosToken($refreshToken);
            $feedback = 'Refresh token Lexos executado e tokens salvos.';
            $apiConfig = $app['settingsRepository']->getApiConfig();
            try {
                $trackingApiBase = preg_replace('#/admin/dashboard$#', '', (string) ($trackingWctUrl ?? '')) ?: 'http://localhost:3001';
                $sync = new \App\Services\TrackingLexosSyncService(
                    $app['settingsRepository'],
                    $app['trackingLexosTokenRepository'],
                    $trackingApiBase
                );
                $syncResult = $sync->syncFromPortalConfig();
                $feedback .= ' Tracking: ' . ($syncResult['message'] ?? 'sincronizado.');
            } catch (Throwable $syncErr) {
                $feedback .= ' Aviso Tracking: ' . $syncErr->getMessage();
                $feedbackClass = 'err';
            }
        }

        if ($formType === 'lexos_sync_tracking') {
            $trackingApiBase = preg_replace('#/admin/dashboard$#', '', (string) ($trackingWctUrl ?? '')) ?: 'http://localhost:3001';
            $sync = new \App\Services\TrackingLexosSyncService(
                $app['settingsRepository'],
                $app['trackingLexosTokenRepository'],
                $trackingApiBase
            );
            $syncResult = $sync->syncFromPortalConfig();
            $feedback = $syncResult['message'] ?? 'Credenciais enviadas ao Tracking.';
            if (($syncResult['mode'] ?? '') === 'database' && isset($syncResult['tracking'])) {
                $st = $syncResult['tracking'];
                $feedback .= ' Token: ' . (($st['has_token'] ?? false) ? 'sim' : 'não');
                $feedback .= '; Chave: ' . (($st['has_chave'] ?? false) ? 'sim' : 'não');
                if (($st['has_refresh'] ?? false)) {
                    $feedback .= '; Refresh: sim';
                }
            }
        }

        if ($formType === 'lexos_import_tracking_key') {
            $testUrl = trim((string) ($_POST['tracking_database_url'] ?? ''));
            if ($testUrl === '') {
                $existing = $app['settingsRepository']->getApiConfig() ?? [];
                $testUrl = trim((string) ($existing['tracking_database_url'] ?? ''));
            }
            if ($testUrl === '') {
                $env = getenv('TRACKING_DATABASE_URL');
                $testUrl = is_string($env) ? trim($env) : '';
            }
            $repo = new \App\Repositories\TrackingLexosTokenRepository(
                new \App\Core\TrackingDatabase($testUrl)
            );
            $test = $repo->testConnection();
            if (!$test['ok'] || $test['row'] === null) {
                throw new RuntimeException(
                    'Não foi possível ler o Tracking. Configure TRACKING_DATABASE_URL no Render do Portal ou cole a URL PostgreSQL acima.'
                );
            }
            $chave = trim((string) ($test['row']['chave'] ?? ''));
            if ($chave === '') {
                throw new RuntimeException('O Tracking não possui chave salva em lexos_tokens.');
            }
            $existing = $app['settingsRepository']->getApiConfig() ?? [];
            $app['settingsRepository']->saveApiConfig([
                'app_id' => trim((string) ($existing['app_id'] ?? '')),
                'client_secret' => trim((string) ($existing['client_secret'] ?? '')),
                'redirect_uri' => trim((string) ($existing['redirect_uri'] ?? '')),
                'seller_id' => trim((string) ($existing['seller_id'] ?? '')),
                'oauth_code' => trim((string) ($existing['oauth_code'] ?? '')),
                'lexos_code' => trim((string) ($existing['lexos_code'] ?? '')),
                'lexos_token' => trim((string) ($existing['lexos_token'] ?? '')),
                'lexos_refresh_token' => trim((string) ($existing['lexos_refresh_token'] ?? '')),
                'lexos_integration_key' => $chave,
                'lexos_integration_header_name' => trim((string) ($existing['lexos_integration_header_name'] ?? '')),
                'tracking_database_url' => $testUrl !== '' ? $testUrl : trim((string) ($existing['tracking_database_url'] ?? '')),
                'lexos_credentials_mode' => trim((string) ($existing['lexos_credentials_mode'] ?? 'auto')),
            ]);
            $feedback = 'Chave Lexos importada do Tracking para o Portal. Salve ou envie ao Tracking quando quiser.';
            $apiConfig = $app['settingsRepository']->getApiConfig();
        }

        if ($formType === 'token') {
            $app['tokenService']->saveInitialToken([
                'access_token' => trim((string) ($_POST['access_token'] ?? '')),
                'refresh_token' => trim((string) ($_POST['refresh_token'] ?? '')),
                'expires_in' => (int) ($_POST['expires_in'] ?? 21600),
                'token_type' => 'Bearer',
            ]);
            $feedback = 'Token inicial salvo com sucesso.';
        }

        if ($formType === 'refresh') {
            $app['tokenService']->refreshToken();
            $feedback = 'Refresh token executado com sucesso.';
        }

        if ($formType === 'mp_token') {
            $app['mercadopagoSettingsRepository']->saveAccessToken(trim((string) ($_POST['mp_access_token'] ?? '')));
            $feedback = 'Access token do Mercado Pago salvo.';
        }

        if ($formType === 'ml_token_from_code') {
            $config = $app['settingsRepository']->getApiConfig();
            if (!$config) {
                throw new RuntimeException('Salve App ID, Client Secret e Redirect URI antes de trocar o code.');
            }

            $code = trim((string) ($_POST['oauth_code'] ?? ($config['oauth_code'] ?? '')));
            $result = $app['mercadoLivreOAuthService']->exchangeCode(
                (string) $config['app_id'],
                (string) $config['client_secret'],
                (string) $config['redirect_uri'],
                $code
            );

            if ($result['status'] < 200 || $result['status'] >= 300) {
                $body = is_array($result['body']) ? $result['body'] : [];
                throw new RuntimeException(
                    'Falha ao trocar code (HTTP ' . $result['status'] . '): '
                    . $app['mercadoLivreOAuthService']->oauthErrorHint($body)
                );
            }

            $body = $result['body'];
            $app['tokenService']->saveInitialToken([
                'access_token' => (string) ($body['access_token'] ?? ''),
                'refresh_token' => (string) ($body['refresh_token'] ?? ''),
                'expires_in' => (int) ($body['expires_in'] ?? 21600),
                'token_type' => (string) ($body['token_type'] ?? 'Bearer'),
                'scope' => $body['scope'] ?? null,
            ]);

            $existing = $config;
            $sellerId = (string) ($body['user_id'] ?? ($existing['seller_id'] ?? ''));
            $app['settingsRepository']->saveApiConfig([
                'app_id' => (string) $existing['app_id'],
                'client_secret' => (string) $existing['client_secret'],
                'redirect_uri' => (string) $existing['redirect_uri'],
                'seller_id' => $sellerId,
                'oauth_code' => $app['mercadoLivreOAuthService']->normalizeOauthCode($code),
                'lexos_code' => (string) ($existing['lexos_code'] ?? ''),
                'lexos_token' => (string) ($existing['lexos_token'] ?? ''),
                'lexos_refresh_token' => (string) ($existing['lexos_refresh_token'] ?? ''),
                'lexos_integration_key' => (string) ($existing['lexos_integration_key'] ?? ''),
                'lexos_integration_header_name' => (string) ($existing['lexos_integration_header_name'] ?? ''),
                'tracking_database_url' => (string) ($existing['tracking_database_url'] ?? ''),
                'lexos_credentials_mode' => (string) ($existing['lexos_credentials_mode'] ?? 'auto'),
            ]);

            $feedback = 'Tokens ML gerados e salvos. Seller ID: ' . $sellerId . '.';
            $apiConfig = $app['settingsRepository']->getApiConfig();
            $token = $app['tokenRepository']->getLatestToken();
        }

        if ($formType === 'check-seller-id') {
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
$lexosCredStatus = $app['lexosCredentialsService']->getStatusSummary();
$token = $app['tokenRepository']->getLatestToken();

if ($apiConfig
    && trim((string) ($apiConfig['app_id'] ?? '')) !== ''
    && trim((string) ($apiConfig['redirect_uri'] ?? '')) !== ''
) {
    $mlAuthUrl = $app['mercadoLivreOAuthService']->buildAuthUrl(
        (string) $apiConfig['app_id'],
        (string) $apiConfig['redirect_uri']
    );
}
$mpRow = $app['mercadopagoSettingsRepository']->getSettings();
$requestLogs = $app['requestLogRepository']->listRecent(10);

$baseUrl = $app['config']['app']['base_url'];
$apiTabUrl = static function (string $tabId) use ($baseUrl): string {
    return portal_wct_public_path($baseUrl, 'index.php?page=api-config&api_tab=' . urlencode($tabId));
};
?>
<style>
    .api-config-tabs {
        display: flex;
        gap: 6px;
        border-bottom: 1px solid #e5e7eb;
        margin-bottom: 0;
        flex-wrap: wrap;
    }
    .api-config-tab-btn {
        margin: 0;
        background: #f8fafc;
        color: #334155;
        border: 1px solid #e2e8f0;
        border-bottom: none;
        border-radius: 8px 8px 0 0;
        padding: 10px 16px;
        text-transform: none;
        letter-spacing: 0;
        font-size: 0.9rem;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        box-sizing: border-box;
    }
    .api-config-tab-btn.active {
        background: #fff;
        color: #2563eb;
        border-color: #cbd5e1;
        font-weight: 700;
    }
    .api-config-tab-panel {
        display: none;
    }
    .api-config-tab-panel.active {
        display: block;
    }
    .api-config-card {
        border-top-left-radius: 0;
    }
    .api-config-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }
</style>

<section class="card api-config-card">
    <h1>Configuração de APIs</h1>

    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <nav class="api-config-tabs" aria-label="Integrações">
        <a class="api-config-tab-btn<?= $apiTab === 'ml' ? ' active' : '' ?>" href="<?= htmlspecialchars($apiTabUrl('ml')) ?>">Mercado Livre</a>
        <a class="api-config-tab-btn<?= $apiTab === 'lexos' ? ' active' : '' ?>" href="<?= htmlspecialchars($apiTabUrl('lexos')) ?>">Lexos</a>
        <a class="api-config-tab-btn<?= $apiTab === 'mp' ? ' active' : '' ?>" href="<?= htmlspecialchars($apiTabUrl('mp')) ?>">Mercado Pago</a>
    </nav>

    <div class="api-config-tab-panel<?= $apiTab === 'ml' ? ' active' : '' ?>" data-tab="ml">
        <h2 style="margin-top:1rem;font-size:1.1rem">Aplicativo e OAuth</h2>
        <form method="post">
            <input type="hidden" name="api_tab" value="ml">
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

        <?php if ($mlAuthUrl): ?>
            <p style="margin-top:1rem">
                <strong>1.</strong> Abra o link de autorização (conta vendedor ML):
                <a href="<?= htmlspecialchars($mlAuthUrl) ?>" target="_blank" rel="noopener">Autorizar aplicativo</a>
            </p>
            <p><strong>2.</strong> Após autorizar, copie o <code>code</code> da URL de retorno (TG-...) e salve acima ou use o botão abaixo.</p>
        <?php else: ?>
            <p style="margin-top:1rem">Preencha App ID e Redirect URI e salve para gerar o link de autorização.</p>
        <?php endif; ?>

        <form method="post" class="api-config-actions">
            <input type="hidden" name="api_tab" value="ml">
            <input type="hidden" name="form_type" value="ml_token_from_code">
            <input type="hidden" name="oauth_code" value="<?= htmlspecialchars((string) ($apiConfig['oauth_code'] ?? '')) ?>">
            <button type="submit">Trocar code por tokens (ML)</button>
        </form>

        <h2 style="margin-top:1.5rem;font-size:1.1rem">Token OAuth</h2>
        <p>Guarde o token atual e o refresh token para renovação automática.</p>
        <form method="post">
            <input type="hidden" name="api_tab" value="ml">
            <input type="hidden" name="form_type" value="token">
            <label>Access Token</label>
            <textarea name="access_token" rows="4" required><?= htmlspecialchars((string) ($token['access_token'] ?? '')) ?></textarea>

            <label>Refresh Token</label>
            <textarea name="refresh_token" rows="3" required><?= htmlspecialchars((string) ($token['refresh_token'] ?? '')) ?></textarea>

            <label>Expira em (segundos)</label>
            <input type="number" name="expires_in" value="<?= htmlspecialchars((string) ($token['expires_in'] ?? '21600')) ?>" min="60" required>

            <button type="submit">Salvar Token</button>
        </form>

        <div class="api-config-actions">
            <form method="post">
                <input type="hidden" name="api_tab" value="ml">
                <input type="hidden" name="form_type" value="refresh">
                <button type="submit">Executar Refresh Agora</button>
            </form>
            <form method="post">
                <input type="hidden" name="api_tab" value="ml">
                <input type="hidden" name="form_type" value="check-seller-id">
                <button type="submit">Verificar ID Correto do Token</button>
            </form>
        </div>

        <?php if ($token): ?>
            <p>Expira em: <strong><?= htmlspecialchars((string) $token['expires_at']) ?></strong></p>
        <?php endif; ?>
        <?php if ($detectedSellerId): ?>
            <p>ID detectado no token atual: <strong><?= htmlspecialchars($detectedSellerId) ?></strong></p>
        <?php endif; ?>

        <h2 style="margin-top:1.5rem;font-size:1.1rem">Últimos 10 requests (API Mercado Livre)</h2>
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
    </div>

    <div class="api-config-tab-panel<?= $apiTab === 'lexos' ? ' active' : '' ?>" data-tab="lexos">
        <h2 style="margin-top:1rem;font-size:1.1rem">API Lexos</h2>

        <section class="lexos-tracking-db-panel" style="margin-bottom:1.25rem;padding:1rem;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc">
            <h3 style="margin:0 0 .5rem;font-size:1rem">Banco do Tracking (credenciais Lexos)</h3>
            <p style="margin:0 0 .75rem;font-size:.9rem;color:#475569">
                O portal pode ler <code>lexos_tokens</code> do PostgreSQL do Tracking WCT (mesmos token e chave usados no webhook).
                Alternativa: variável de ambiente <code>TRACKING_DATABASE_URL</code> no Render.
            </p>
            <?php
            $trk = $lexosCredStatus['tracking'];
            $credMode = (string) ($apiConfig['lexos_credentials_mode'] ?? 'auto');
            ?>
            <p class="feedback <?= $lexosCredStatus['has_token'] && $lexosCredStatus['has_key'] ? 'ok' : 'err' ?>" style="margin-bottom:.75rem">
                Credenciais efetivas:
                <?= $lexosCredStatus['has_token'] && $lexosCredStatus['has_key'] ? 'prontas' : 'incompletas' ?>
                (origem: <strong><?= htmlspecialchars($lexosCredStatus['source']) ?></strong>, modo: <?= htmlspecialchars($credMode) ?>)
            </p>
            <?php if ($trk['connected'] ?? false): ?>
                <ul style="margin:0 0 .75rem;padding-left:1.2rem;font-size:.9rem">
                    <li>Tracking DB: <?= ($trk['has_row'] ?? false) ? 'registro encontrado' : 'conectado, sem linha em lexos_tokens' ?></li>
                    <?php if ($trk['has_row'] ?? false): ?>
                        <li>Token no Tracking: <?= ($trk['has_token'] ?? false) ? htmlspecialchars((string) $trk['token_preview']) : 'ausente' ?></li>
                        <li>Chave no Tracking: <?= ($trk['has_chave'] ?? false) ? htmlspecialchars((string) $trk['chave_preview']) : 'ausente' ?></li>
                        <?php if (($trk['atualizado_em'] ?? '') !== ''): ?>
                            <li>Atualizado: <?= htmlspecialchars((string) $trk['atualizado_em']) ?></li>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
            <?php else: ?>
                <p style="font-size:.9rem;color:#64748b;margin:0 0 .75rem">
                    URL do Tracking não configurada no formulário.
                    <?php
                    $envTrk = getenv('TRACKING_DATABASE_URL');
                    $hasEnvTrk = is_string($envTrk) && trim($envTrk) !== '';
                    ?>
                    <?php if ($hasEnvTrk): ?>
                        Variável <code>TRACKING_DATABASE_URL</code> detectada no servidor — use <strong>Testar conexão Tracking</strong>.
                    <?php else: ?>
                        Configure <code>TRACKING_DATABASE_URL</code> no Render do Portal (mesmo Postgres do Tracking) ou cole a URL acima.
                    <?php endif; ?>
                </p>
            <?php endif; ?>

        </section>

        <form method="post" id="lexos-api-form">
            <input type="hidden" name="api_tab" value="lexos">
            <input type="hidden" name="form_type" value="lexos_api">

            <label>URL PostgreSQL do Tracking</label>
            <input type="password" id="tracking_database_url" name="tracking_database_url" autocomplete="off"
                   placeholder="postgresql://usuario:senha@host:5432/tracking"
                   value="<?= htmlspecialchars((string) ($apiConfig['tracking_database_url'] ?? '')) ?>"
                   style="width:100%;font-family:monospace;font-size:.85rem">

            <label style="margin-top:.75rem">Origem das credenciais Lexos</label>
            <select name="lexos_credentials_mode" style="max-width:320px">
                <option value="auto" <?= $credMode === 'auto' ? 'selected' : '' ?>>Automático (Tracking se houver token/chave)</option>
                <option value="tracking" <?= $credMode === 'tracking' ? 'selected' : '' ?>>Somente Tracking</option>
                <option value="portal" <?= $credMode === 'portal' ? 'selected' : '' ?>>Somente portal (campos abaixo)</option>
            </select>
            <p style="font-size:.85rem;color:#64748b;margin:.35rem 0 1rem">
                No modo automático, token e chave preenchidos abaixo prevalecem sobre o Tracking.
                O <strong>webhook Lexos</strong> envia eventos de pedido — <strong>não envia o Code OAuth</strong>.
                Para o Tracking, use <strong>Refresh Token + Chave</strong> ou o botão &quot;Enviar credenciais ao Tracking&quot;.
            </p>

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

        <form method="post" class="api-config-actions" style="margin-top:.5rem">
            <input type="hidden" name="api_tab" value="lexos">
            <input type="hidden" name="form_type" value="lexos_tracking_test">
            <input type="hidden" name="tracking_database_url" id="tracking_test_url"
                   value="<?= htmlspecialchars((string) ($apiConfig['tracking_database_url'] ?? '')) ?>">
            <button type="submit"
                    onclick="document.getElementById('tracking_test_url').value=document.getElementById('tracking_database_url').value">
                Testar conexão Tracking
            </button>
        </form>

        <div class="api-config-actions">
            <form method="post">
                <input type="hidden" name="api_tab" value="lexos">
                <input type="hidden" name="form_type" value="lexos_sync_tracking">
                <button type="submit">Enviar credenciais ao Tracking</button>
            </form>
            <form method="post" class="api-config-actions" style="margin-top:.5rem">
                <input type="hidden" name="api_tab" value="lexos">
                <input type="hidden" name="form_type" value="lexos_import_tracking_key">
                <input type="hidden" name="tracking_database_url" id="tracking_import_url"
                       value="<?= htmlspecialchars((string) ($apiConfig['tracking_database_url'] ?? '')) ?>">
                <button type="submit"
                        onclick="document.getElementById('tracking_import_url').value=document.getElementById('tracking_database_url').value">
                    Importar Key do Tracking
                </button>
            </form>
            <form method="post">
                <input type="hidden" name="api_tab" value="lexos">
                <input type="hidden" name="form_type" value="lexos_token_from_code">
                <input type="hidden" name="lexos_code" value="<?= htmlspecialchars((string) ($apiConfig['lexos_code'] ?? '')) ?>">
                <button type="submit">Gerar Token Lexos via Code</button>
            </form>
            <form method="post">
                <input type="hidden" name="api_tab" value="lexos">
                <input type="hidden" name="form_type" value="lexos_refresh_token">
                <input type="hidden" name="lexos_refresh_token" value="<?= htmlspecialchars((string) ($apiConfig['lexos_refresh_token'] ?? '')) ?>">
                <button type="submit">Executar Refresh Token Lexos</button>
            </form>
        </div>
    </div>

    <div class="api-config-tab-panel<?= $apiTab === 'mp' ? ' active' : '' ?>" data-tab="mp">
        <h2 style="margin-top:1rem;font-size:1.1rem">Token Mercado Pago (Repasse MP)</h2>
        <p>Token usado nas rotinas da tela <strong>Repasse MP</strong>.</p>
        <form method="post">
            <input type="hidden" name="api_tab" value="mp">
            <input type="hidden" name="form_type" value="mp_token">
            <label>Access Token (Mercado Pago)</label>
            <textarea name="mp_access_token" rows="3" placeholder="APP_USR-..."><?= htmlspecialchars((string) ($mpRow['access_token'] ?? '')) ?></textarea>
            <button type="submit">Salvar token MP</button>
        </form>
    </div>
</section>
