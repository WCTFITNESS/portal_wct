<?php

declare(strict_types=1);

use App\Services\LexosHubBrowserCacheService;

$cache = $app['lexosHubBrowserCacheService'];
$captureUrl = portal_wct_absolute_url($baseUrl, 'index.php?page=lexos-hub-connect&action=capture');
$statusUrl = portal_wct_absolute_url($baseUrl, 'index.php?page=lexos-hub-connect&action=status');
$syncUrl = portal_wct_absolute_url($baseUrl, 'index.php?page=lexos-hub-connect&action=sync');
$bookmarklet = $cache->buildBookmarklet($captureUrl);
$hubStatus = $app['lexosCredentialsService']->getHubStatusSummary();
$hasRefresh = $app['lexosCredentialsService']->getHubRefreshToken() !== '';
$hasAccess = ($hubStatus['has_hub_token'] ?? false);
$captured = ($_GET['captured'] ?? '') === '1';
$errorMsg = trim((string) ($_GET['error'] ?? ''));
$extensionPath = 'tools/lexos-portal-sync';
?>
<section class="card">
    <h1>Conectar Lexos Hub</h1>
    <p style="color:#475569;max-width:760px">
        Configuração <strong>única de TI</strong>. Depois disso, a aba <em>Produtos</em> funciona para todos os usuários.
        Usuários finais não instalam nada.
    </p>

    <?php if ($captured): ?>
        <div class="msg ok">Tokens do Hub salvos com sucesso.</div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
        <div class="msg err"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div style="margin:1rem 0;padding:16px;border:2px solid #3b82f6;border-radius:10px;background:#eff6ff">
        <h2 style="margin:0 0 8px;font-size:1.05rem">Modo automático (recomendado)</h2>
        <p style="margin:0 0 12px;font-size:.9rem;color:#1e3a8a">
            Instale o conector uma vez no Chrome/Edge. Ele sincroniza sozinho sempre que você abrir o Hub Lexos.
        </p>
        <ol style="margin:0 0 14px;padding-left:1.25rem;font-size:.9rem">
            <li>Chrome → <code>chrome://extensions</code> → Modo desenvolvedor → <strong>Carregar sem compactação</strong></li>
            <li>Pasta: <code><?= htmlspecialchars($extensionPath) ?></code> (dentro do repositório do portal)</li>
            <li>Volte aqui neste portal (Render) — a extensão grava a URL de sync automaticamente</li>
        </ol>
        <p style="margin:0 0 10px">
            <button type="button" id="lexos-hub-auto-connect-btn" class="btn" style="font-weight:700">Conectar automaticamente agora</button>
        </p>
        <div id="lexos-hub-auto-status" style="font-size:.88rem;color:#1e3a8a"></div>
        <div id="lexos-hub-extension-detect" style="margin-top:8px;font-size:.85rem"></div>
    </div>

    <div style="margin:1rem 0;padding:14px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;font-size:.92rem">
        <div><strong>Servidor:</strong>
            refresh <?= $hasRefresh ? 'configurado' : 'pendente' ?> —
            access <?= $hasAccess ? 'ok (' . htmlspecialchars((string) ($hubStatus['hub_token_preview'] ?? '')) . ')' : 'ausente' ?>
        </div>
        <div id="lexos-hub-cache-status" style="margin-top:.5rem"><strong>Cache do navegador:</strong> verificando…</div>
    </div>

    <details style="margin:1rem 0">
        <summary style="cursor:pointer;font-weight:600">Alternativa manual (favorito)</summary>
        <ol style="margin:.75rem 0 0;padding-left:1.25rem;font-size:.9rem">
            <li>Arraste para favoritos:
                <a href="<?= htmlspecialchars($bookmarklet, ENT_QUOTES, 'UTF-8') ?>" style="font-weight:700">Capturar Hub → Portal</a>
            </li>
            <li>Abra <a href="https://app-hub.lexos.com.br" target="_blank" rel="noopener">app-hub.lexos.com.br</a> logado</li>
            <li>Clique no favorito</li>
        </ol>
    </details>

    <p>
        <button type="button" id="lexos-hub-sync-cache-btn" class="btn">Enviar cache do navegador → servidor</button>
        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=api-config&api_tab=lexos'), ENT_QUOTES, 'UTF-8') ?>" style="margin-left:.75rem">Configuração API</a>
        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=dashboard&lexos_tab=products'), ENT_QUOTES, 'UTF-8') ?>" style="margin-left:.75rem">Testar Produtos</a>
    </p>
</section>

<script src="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'assets/js/lexos-hub-cache.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'assets/js/lexos-hub-auto-connect.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    var syncUrl = <?= json_encode($syncUrl, JSON_UNESCAPED_SLASHES) ?>;
    var statusUrl = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
    var hubUrl = 'https://app-hub.lexos.com.br/';
    var autoBtn = document.getElementById('lexos-hub-auto-connect-btn');
    var autoStatus = document.getElementById('lexos-hub-auto-status');
    var extDetect = document.getElementById('lexos-hub-extension-detect');

    function setAutoStatus(text, ok) {
        if (!autoStatus) return;
        autoStatus.textContent = text;
        autoStatus.style.color = ok ? '#166534' : '#9a3412';
    }

    if (typeof window.WctLexosHubCache !== 'undefined') {
        window.WctLexosHubCache.renderStatus('lexos-hub-cache-status');
        window.WctLexosHubCache.syncToServer(syncUrl, { silent: true });
        document.getElementById('lexos-hub-sync-cache-btn').addEventListener('click', function () {
            window.WctLexosHubCache.syncToServer(syncUrl).then(function (r) {
                alert(r.ok ? r.message : ('Erro: ' + r.message));
                if (r.ok) location.reload();
            });
        });
    }

    if (typeof window.WctLexosHubAutoConnect !== 'undefined' && extDetect) {
        window.WctLexosHubAutoConnect.pingExtension().then(function (ok) {
            extDetect.textContent = ok
                ? 'Conector detectado no navegador.'
                : 'Conector não detectado — instale a extensão (passos acima) e recarregue esta página.';
            extDetect.style.color = ok ? '#166534' : '#b45309';
        });
    }

    if (autoBtn && typeof window.WctLexosHubAutoConnect !== 'undefined') {
        autoBtn.addEventListener('click', function () {
            autoBtn.disabled = true;
            setAutoStatus('Abrindo Hub Lexos… faça login se necessário. Aguardando sincronização automática…', true);
            window.WctLexosHubAutoConnect.openHubAndWait(statusUrl, hubUrl)
                .then(function () {
                    setAutoStatus('Conectado! Sessão Hub salva no servidor. Abrindo Produtos…', true);
                    setTimeout(function () {
                        location.href = <?= json_encode(portal_wct_public_path($baseUrl, 'index.php?page=dashboard&lexos_tab=products'), JSON_UNESCAPED_SLASHES) ?>;
                    }, 800);
                })
                .catch(function (err) {
                    setAutoStatus((err && err.message) || 'Falha na conexão automática.', false);
                    autoBtn.disabled = false;
                });
        });
    }
})();
</script>
