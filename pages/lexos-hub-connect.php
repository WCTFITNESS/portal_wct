<?php

declare(strict_types=1);

use App\Services\LexosHubBrowserCacheService;

$cache = $app['lexosHubBrowserCacheService'];
$captureUrl = portal_wct_absolute_url($baseUrl, 'index.php?page=lexos-hub-connect&action=capture');
$statusUrl = portal_wct_absolute_url($baseUrl, 'index.php?page=lexos-hub-connect&action=status');
$syncUrl = portal_wct_absolute_url($baseUrl, 'index.php?page=lexos-hub-connect&action=sync');
$bookmarklet = $cache->buildBookmarklet($captureUrl);
$hubStatus = $app['lexosCredentialsService']->getHubStatusSummary();
$hubDiagnostic = $app['lexosHubSessionService']->diagnoseHubSession();
$hasRefresh = (bool) ($hubDiagnostic['has_refresh'] ?? false);
$hasAccess = (bool) ($hubDiagnostic['has_access'] ?? false);
$sessionOk = (bool) ($hubDiagnostic['session_ok'] ?? false);
$probeOk = (bool) ($hubDiagnostic['probe_ok'] ?? false);
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

    <?php if ($sessionOk): ?>
        <div class="msg ok">
            <strong>Servidor pronto.</strong> A aba Produtos deve funcionar para todos os usuários.
            <?php if ($probeOk): ?> API Hub respondeu OK.<?php endif; ?>
        </div>
    <?php else: ?>
        <div class="msg err">
            <strong>Ainda não conectado no servidor Render.</strong>
            <?= htmlspecialchars((string) ($hubDiagnostic['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div style="margin:1rem 0;padding:14px;border:1px solid <?= $sessionOk ? '#bbf7d0' : '#fecaca' ?>;border-radius:8px;background:<?= $sessionOk ? '#f0fdf4' : '#fef2f2' ?>;font-size:.92rem">
        <div><strong>Servidor (Render — o que importa para Produtos):</strong></div>
        <ul style="margin:.5rem 0 0;padding-left:1.25rem">
            <li>Refresh Hub: <?= $hasRefresh ? '<span style="color:#166534">configurado</span>' : '<span style="color:#b45309">pendente</span>' ?></li>
            <li>Access Hub: <?= $hasAccess ? '<span style="color:#166534">ok (' . htmlspecialchars((string) ($hubStatus['hub_token_preview'] ?? ''), ENT_QUOTES, 'UTF-8') . ')</span>' : '<span style="color:#b45309">ausente</span>' ?></li>
            <li>Sessão / API Produtos: <?= $sessionOk ? '<span style="color:#166534">pronta</span>' : '<span style="color:#b45309">indisponível</span>' ?></li>
        </ul>
        <div id="lexos-hub-cache-status" style="margin-top:.65rem"><strong>Cache deste navegador:</strong> verificando…</div>
        <p style="margin:.65rem 0 0;font-size:.85rem;color:#64748b">
            O cache do navegador <em>não basta</em> — os tokens precisam estar salvos no <strong>servidor</strong> (Render).
        </p>
    </div>

    <div id="lexos-hub-bookmarklet-box" style="margin:1rem 0;padding:16px;border:2px solid #16a34a;border-radius:10px;background:#f0fdf4">
        <h2 style="margin:0 0 8px;font-size:1.05rem">Conectar agora (sem extensão)</h2>
        <ol style="margin:0 0 12px;padding-left:1.25rem;font-size:.9rem">
            <li>Arraste para favoritos:
                <a href="<?= htmlspecialchars($bookmarklet, ENT_QUOTES, 'UTF-8') ?>" style="font-weight:700">Capturar Hub → Portal</a>
            </li>
            <li>Abra <a href="https://app-hub.lexos.com.br" target="_blank" rel="noopener">app-hub.lexos.com.br</a> logado</li>
            <li>Clique no favorito — abrirá esta página com «Tokens salvos com sucesso»</li>
            <li>Clique em <strong>Testar Produtos</strong> abaixo</li>
        </ol>
    </div>

    <div style="margin:1rem 0;padding:16px;border:2px solid #3b82f6;border-radius:10px;background:#eff6ff">
        <h2 style="margin:0 0 8px;font-size:1.05rem">Modo automático (extensão Chrome)</h2>
        <p style="margin:0 0 12px;font-size:.9rem;color:#1e3a8a">
            Opcional: instale o conector uma vez. Ele sincroniza sozinho quando você abrir o Hub Lexos.
        </p>
        <ol style="margin:0 0 14px;padding-left:1.25rem;font-size:.9rem">
            <li>Chrome → <code>chrome://extensions</code> → Modo desenvolvedor → <strong>Carregar sem compactação</strong></li>
            <li>Pasta: <code><?= htmlspecialchars($extensionPath) ?></code> (dentro do repositório do portal)</li>
            <li>Recarregue <strong>esta página</strong> — deve aparecer «Conector detectado»</li>
        </ol>
        <p style="margin:0 0 10px">
            <button type="button" id="lexos-hub-auto-connect-btn" class="btn" style="font-weight:700" disabled>Conectar automaticamente agora</button>
        </p>
        <div id="lexos-hub-auto-status" style="font-size:.88rem"></div>
        <div id="lexos-hub-extension-detect" style="margin-top:8px;font-size:.85rem;font-weight:600">Verificando conector…</div>
    </div>

    <details style="margin:1rem 0;display:none" id="lexos-hub-bookmarklet-details">
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
    var extensionReady = false;

    function setAutoStatus(text, ok) {
        if (!autoStatus) return;
        autoStatus.textContent = text;
        autoStatus.style.color = ok ? '#166534' : '#9a3412';
    }

    function detectExtension() {
        if (document.documentElement.getAttribute('data-wct-lexos-extension') === '1') {
            return Promise.resolve(true);
        }
        if (typeof window.WctLexosHubAutoConnect === 'undefined') {
            return Promise.resolve(false);
        }
        return window.WctLexosHubAutoConnect.pingExtension();
    }

    if (typeof window.WctLexosHubCache !== 'undefined') {
        window.WctLexosHubCache.renderStatus('lexos-hub-cache-status');
        document.getElementById('lexos-hub-sync-cache-btn').addEventListener('click', function () {
            window.WctLexosHubCache.syncToServer(syncUrl).then(function (r) {
                alert(r.ok ? r.message : ('Erro: ' + r.message));
                if (r.ok) location.reload();
            });
        });
    }

    detectExtension().then(function (ok) {
        extensionReady = ok;
        if (extDetect) {
            extDetect.textContent = ok
                ? 'Conector detectado no navegador.'
                : 'Conector não detectado — use o favorito «Capturar Hub → Portal» (caixa verde acima) ou instale a extensão.';
            extDetect.style.color = ok ? '#166534' : '#b45309';
        }
        if (autoBtn) {
            autoBtn.disabled = !ok;
            if (!ok) {
                setAutoStatus('Instale a extensão e recarregue esta página, ou use o favorito na caixa verde.', false);
            }
        }
    });

    if (autoBtn && typeof window.WctLexosHubAutoConnect !== 'undefined') {
        autoBtn.addEventListener('click', function () {
            detectExtension().then(function (ok) {
                if (!ok) {
                    setAutoStatus('Conector não detectado. Use o favorito «Capturar Hub → Portal» (caixa verde).', false);
                    return;
                }
                autoBtn.disabled = true;
                setAutoStatus('Abrindo Hub Lexos… aguardando sincronização da extensão…', true);
                window.WctLexosHubAutoConnect.openHubAndWait(statusUrl, hubUrl)
                    .then(function () {
                        setAutoStatus('Conectado no servidor! Abrindo Produtos…', true);
                        setTimeout(function () {
                            location.href = <?= json_encode(portal_wct_public_path($baseUrl, 'index.php?page=dashboard&lexos_tab=products'), JSON_UNESCAPED_SLASHES) ?>;
                        }, 800);
                    })
                    .catch(function (err) {
                        setAutoStatus((err && err.message) || 'Tempo esgotado — faça login no Hub e use o favorito Capturar Hub → Portal.', false);
                        autoBtn.disabled = false;
                    });
            });
        });
    }
})();
</script>
