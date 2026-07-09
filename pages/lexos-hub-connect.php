<?php

declare(strict_types=1);

use App\Services\LexosHubBrowserCacheService;

$cache = $app['lexosHubBrowserCacheService'];
$captureUrl = portal_wct_absolute_url($baseUrl, 'index.php?page=lexos-hub-connect&action=capture');
$bookmarklet = $cache->buildBookmarklet($captureUrl);
$hubStatus = $app['lexosCredentialsService']->getHubStatusSummary();
$hasRefresh = $app['lexosCredentialsService']->getHubRefreshToken() !== '';
$captured = ($_GET['captured'] ?? '') === '1';
$errorMsg = trim((string) ($_GET['error'] ?? ''));
?>
<section class="card">
    <h1>Conectar Lexos Hub</h1>
    <p style="color:#475569;max-width:720px">
        Captura o <strong>access_token</strong> (como o plugin Faturamento), refresh, cookies e todo o
        <strong>localStorage</strong> de
        <a href="https://app-hub.lexos.com.br" target="_blank" rel="noopener">app-hub.lexos.com.br</a>
        e salva no <strong>cache do navegador</strong> + <strong>banco do portal</strong>.
        Usuários finais não precisam fazer nada depois disso.
    </p>

    <?php if ($captured): ?>
        <div class="msg ok">Tokens do Hub salvos com sucesso. A aba Produtos pode usar a sessão automaticamente.</div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
        <div class="msg err"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div style="margin:1rem 0;padding:14px;border:1px solid #bbf7d0;border-radius:8px;background:#f0fdf4">
        <strong>Passo a passo (TI — 1x):</strong>
        <ol style="margin:.6rem 0 0;padding-left:1.25rem">
            <li>Arraste para a barra de favoritos:
                <a href="<?= htmlspecialchars($bookmarklet, ENT_QUOTES, 'UTF-8') ?>" style="font-weight:700">Capturar Hub → Portal</a>
            </li>
            <li>Abra <a href="https://app-hub.lexos.com.br" target="_blank" rel="noopener">app-hub.lexos.com.br</a> logado</li>
            <li>Clique no favorito — abre esta página confirmando o salvamento</li>
        </ol>
    </div>

    <div style="margin:1rem 0;padding:14px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;font-size:.92rem">
        <div><strong>Servidor:</strong>
            refresh <?= $hasRefresh ? 'configurado' : 'pendente' ?> —
            access <?= ($hubStatus['has_hub_token'] ?? false) ? 'ok (' . htmlspecialchars((string) ($hubStatus['hub_token_preview'] ?? '')) . ')' : 'ausente' ?>
        </div>
        <div id="lexos-hub-cache-status" style="margin-top:.5rem"><strong>Cache do navegador:</strong> verificando…</div>
    </div>

    <p>
        <button type="button" id="lexos-hub-sync-cache-btn" class="btn">Enviar cache do navegador → servidor</button>
        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=api-config&api_tab=lexos'), ENT_QUOTES, 'UTF-8') ?>" style="margin-left:.75rem">Voltar para Configuração API</a>
    </p>
</section>

<script src="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'assets/js/lexos-hub-cache.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
(function () {
    if (typeof window.WctLexosHubCache === 'undefined') return;
    var syncUrl = <?= json_encode(portal_wct_absolute_url($baseUrl, 'index.php?page=lexos-hub-connect&action=sync'), JSON_UNESCAPED_SLASHES) ?>;
    window.WctLexosHubCache.renderStatus('lexos-hub-cache-status');
    document.getElementById('lexos-hub-sync-cache-btn').addEventListener('click', function () {
        window.WctLexosHubCache.syncToServer(syncUrl).then(function (r) {
            alert(r.ok ? r.message : ('Erro: ' + r.message));
            if (r.ok) location.reload();
        });
    });
    window.WctLexosHubCache.syncToServer(syncUrl, { silent: true });
})();
</script>
