<?php

declare(strict_types=1);

$apiConfig = $app['settingsRepository']->getApiConfig();
$logs = $app['logRepository']->listRecent(10);
$lexos = $app['lexosDashboardService'];

$today = new DateTimeImmutable('now');
$monthStart = $today->modify('first day of this month');
$dStart = trim((string) ($_GET['lexos_start'] ?? $monthStart->format('Y-m-d')));
$dEnd = trim((string) ($_GET['lexos_end'] ?? $today->format('Y-m-d')));
$search = trim((string) ($_GET['lexos_search'] ?? ''));
$sku = trim((string) ($_GET['lexos_sku'] ?? ''));
$metrics = null;
$channels = [];
$products = [];
$skuData = null;
$lexosError = null;
$activeTab = trim((string) ($_GET['lexos_tab'] ?? 'dashboard'));
if (!in_array($activeTab, ['dashboard', 'comparison', 'products', 'sku-analysis'], true)) {
    $activeTab = 'dashboard';
}

try {
    if (($apiConfig['lexos_token'] ?? '') !== '') {
        $metrics = $lexos->getDashboardMetrics($dStart, $dEnd);
        $channels = is_array($metrics['canais'] ?? null) ? $metrics['canais'] : [];
        $products = $lexos->getProducts($dStart, $dEnd, $search, 50);
        if ($sku !== '') {
            $skuData = $lexos->getSkuAnalysis($sku, $dStart, $dEnd);
        }
    }
} catch (Throwable $e) {
    $lexosError = $e->getMessage();
}
?>
<style>
    .lexos-tabs { display:flex; gap:6px; border-bottom:1px solid #e5e7eb; margin-bottom:14px; }
    .lexos-tab-btn { margin:0; background:#f8fafc; color:#334155; border:1px solid #e2e8f0; border-bottom:none; border-radius:8px 8px 0 0; padding:10px 14px; text-transform:none; letter-spacing:0; font-size:.9rem; }
    .lexos-tab-btn.active { background:#fff; color:#2563eb; border-color:#cbd5e1; font-weight:700; }
    .lexos-tab-content { display:none; }
    .lexos-tab-content.active { display:block; }
    .lexos-header { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:12px; }
    .lexos-header h1 { margin:0; }
    .lexos-date-inline { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .lexos-date-inline input { width:auto; min-width:145px; }
    .lexos-metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin:10px 0 14px 0; }
    .lexos-metric { border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:#f8fafc; }
    .lexos-metric strong { display:block; font-size:1.05rem; margin-top:4px; }
</style>

<section class="card">
    <?php if (($apiConfig['lexos_token'] ?? '') === ''): ?>
        <div class="msg err">Configure o <strong>Token Lexos</strong> em Configuração API para habilitar esta seção.</div>
    <?php elseif ($lexosError): ?>
        <div class="msg err">Erro Lexos: <?= htmlspecialchars($lexosError) ?></div>
    <?php endif; ?>

    <nav class="lexos-tabs" id="lexos-tabs">
        <button type="button" class="lexos-tab-btn<?= $activeTab === 'dashboard' ? ' active' : '' ?>" data-tab="dashboard">Dashboard Atual</button>
        <button type="button" class="lexos-tab-btn<?= $activeTab === 'comparison' ? ' active' : '' ?>" data-tab="comparison">Comparação Anual</button>
        <button type="button" class="lexos-tab-btn<?= $activeTab === 'products' ? ' active' : '' ?>" data-tab="products">Produtos</button>
        <button type="button" class="lexos-tab-btn<?= $activeTab === 'sku-analysis' ? ' active' : '' ?>" data-tab="sku-analysis">Análise de SKU</button>
    </nav>

    <div class="lexos-tab-content<?= $activeTab === 'dashboard' ? ' active' : '' ?>" data-content="dashboard">
        <div class="lexos-header">
            <h1>Dashboard de Vendas</h1>
            <form method="get" class="lexos-date-inline">
                <input type="hidden" name="page" value="dashboard">
                <input type="hidden" name="lexos_tab" value="dashboard">
                <label for="lexos-start">Período:</label>
                <input id="lexos-start" type="date" name="lexos_start" value="<?= htmlspecialchars($dStart) ?>">
                <span>até</span>
                <input type="date" name="lexos_end" value="<?= htmlspecialchars($dEnd) ?>">
                <input type="hidden" name="lexos_search" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="lexos_sku" value="<?= htmlspecialchars($sku) ?>">
                <button type="submit">Aplicar</button>
            </form>
        </div>

        <?php if (is_array($metrics)): ?>
            <div class="lexos-metrics">
                <div class="lexos-metric">Faturamento<strong>R$ <?= htmlspecialchars(number_format((float) ($metrics['faturamento'] ?? 0), 2, ',', '.')) ?></strong></div>
                <div class="lexos-metric">Pedidos<strong><?= htmlspecialchars((string) ($metrics['pedidos'] ?? 0)) ?></strong></div>
                <div class="lexos-metric">Ticket Médio<strong>R$ <?= htmlspecialchars(number_format((float) ($metrics['ticket_medio'] ?? 0), 2, ',', '.')) ?></strong></div>
            </div>
        <?php endif; ?>

        <h1>Faturamento por Canal</h1>
        <table>
            <thead><tr><th>Canal</th><th>Faturamento</th></tr></thead>
            <tbody>
            <?php if (!$channels): ?>
                <tr><td colspan="2">Sem dados de canais no período.</td></tr>
            <?php endif; ?>
            <?php foreach ($channels as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($row[0] ?? '')) ?></td>
                    <td>R$ <?= htmlspecialchars(number_format((float) ($row[1] ?? 0), 2, ',', '.')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="lexos-tab-content<?= $activeTab === 'comparison' ? ' active' : '' ?>" data-content="comparison">
        <h1>Comparação Anual</h1>
        <p>Esta aba será evoluída para replicar os cards e gráficos do plugin. Base já preparada no backend.</p>
    </div>

    <div class="lexos-tab-content<?= $activeTab === 'products' ? ' active' : '' ?>" data-content="products">
        <h1>Produtos</h1>
        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="lexos_tab" value="products">
            <input type="hidden" name="lexos_start" value="<?= htmlspecialchars($dStart) ?>">
            <input type="hidden" name="lexos_end" value="<?= htmlspecialchars($dEnd) ?>">
            <label>Buscar SKU/Nome/EAN</label>
            <input type="text" name="lexos_search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="lexos_sku" value="<?= htmlspecialchars($sku) ?>">
            <button type="submit">Filtrar</button>
        </form>
        <table>
            <thead>
            <tr><th>SKU</th><th>Nome</th><th>EAN</th><th>Estoque</th><th>Faturamento</th><th>Quantidade</th><th>Classificação</th></tr>
            </thead>
            <tbody>
            <?php if (!$products): ?>
                <tr><td colspan="7">Nenhum produto encontrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($p['Sku'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($p['Nome'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($p['Ean'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($p['Estoque'] ?? '')) ?></td>
                    <td>R$ <?= htmlspecialchars(number_format((float) ($p['TotalVendidoItem'] ?? 0), 2, ',', '.')) ?></td>
                    <td><?= htmlspecialchars((string) ($p['TotalUnidadesVendidas'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($p['Classificacao'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="lexos-tab-content<?= $activeTab === 'sku-analysis' ? ' active' : '' ?>" data-content="sku-analysis">
        <h1>Análise de SKU</h1>
        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="lexos_tab" value="sku-analysis">
            <input type="hidden" name="lexos_start" value="<?= htmlspecialchars($dStart) ?>">
            <input type="hidden" name="lexos_end" value="<?= htmlspecialchars($dEnd) ?>">
            <input type="hidden" name="lexos_search" value="<?= htmlspecialchars($search) ?>">
            <label>SKU</label>
            <input type="text" name="lexos_sku" value="<?= htmlspecialchars($sku) ?>" placeholder="Digite o SKU">
            <button type="submit">Buscar</button>
        </form>
        <?php if (is_array($skuData)): ?>
            <p>SKU consultado: <strong><?= htmlspecialchars($sku) ?></strong></p>
            <p>Registros de venda: <strong><?= htmlspecialchars((string) count($skuData['sales'] ?? [])) ?></strong></p>
            <p>Registros de estoque: <strong><?= htmlspecialchars((string) count($skuData['stock'] ?? [])) ?></strong></p>
        <?php else: ?>
            <p>Informe um SKU para visualizar os dados.</p>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <h1>Últimos envios</h1>
    <table>
        <thead>
        <tr>
            <th>Pedido</th>
            <th>Cliente</th>
            <th>Status</th>
            <th>Data</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$logs): ?>
            <tr><td colspan="4">Nenhum envio registrado ainda.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= htmlspecialchars((string) $log['order_id']) ?></td>
                <td><?= htmlspecialchars((string) $log['receiver_id']) ?></td>
                <td>
                    <span class="badge <?= $log['status'] === 'sent' ? 'sent' : 'error' ?>">
                        <?= htmlspecialchars((string) $log['status']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars((string) $log['sent_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<script>
(function () {
    var tabs = document.querySelectorAll('#lexos-tabs .lexos-tab-btn');
    var contents = document.querySelectorAll('.lexos-tab-content');
    if (!tabs.length) return;
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-tab') || '';
            tabs.forEach(function (b) { b.classList.toggle('active', b === btn); });
            contents.forEach(function (c) { c.classList.toggle('active', c.getAttribute('data-content') === target); });
            var url = new URL(window.location.href);
            url.searchParams.set('page', 'dashboard');
            url.searchParams.set('lexos_tab', target);
            history.replaceState({}, '', url.toString());
        });
    });
})();
</script>
