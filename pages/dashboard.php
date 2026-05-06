<?php

declare(strict_types=1);

$apiConfig = $app['settingsRepository']->getApiConfig();
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
$productsTotal = 0;
$productsPage = max(1, (int) ($_GET['lexos_products_page'] ?? 1));
$productsPerPage = max(10, min(100, (int) ($_GET['lexos_products_take'] ?? 20)));
$skuData = null;
$lexosError = null;
$activeTab = trim((string) ($_GET['lexos_tab'] ?? 'dashboard'));
if (!in_array($activeTab, ['dashboard', 'comparison', 'products', 'sku-analysis'], true)) {
    $activeTab = 'dashboard';
}

if ($activeTab === 'comparison') {
    $selectedYears = $_GET['lexos_years'] ?? ['2023', '2024', '2025', '2026'];
    if (!is_array($selectedYears) || $selectedYears === []) {
        $selectedYears = ['2023', '2024', '2025', '2026'];
    }
} else {
    $selectedYears = $_GET['lexos_years'] ?? ['2023', '2024', '2025', '2026'];
    if (!is_array($selectedYears) || $selectedYears === []) {
        $selectedYears = ['2023', '2024', '2025', '2026'];
    }
}
$comparison = ['months' => [], 'series' => []];

try {
    if (($apiConfig['lexos_token'] ?? '') !== '') {
        if ($activeTab === 'dashboard') {
            $metrics = $lexos->getDashboardMetrics($dStart, $dEnd);
            $channels = is_array($metrics['canais'] ?? null) ? $metrics['canais'] : [];
        } elseif ($activeTab === 'products') {
            $productsResp = $lexos->getProducts($dStart, $dEnd, $search, $productsPerPage, ($productsPage - 1) * $productsPerPage);
            $products = is_array($productsResp['items'] ?? null) ? $productsResp['items'] : [];
            $productsTotal = (int) ($productsResp['count'] ?? count($products));
        } elseif ($activeTab === 'comparison') {
            $comparison = $lexos->getComparisonData($selectedYears);
        } elseif ($activeTab === 'sku-analysis' && $sku !== '') {
            $skuData = $lexos->getSkuAnalysis($sku, $dStart, $dEnd);
        }
    }
} catch (Throwable $e) {
    $lexosError = $e->getMessage();
}

$channelsChartLabels = [];
$channelsChartValues = [];
foreach ($channels as $row) {
    $channelsChartLabels[] = (string) ($row[0] ?? '');
    $channelsChartValues[] = (float) ($row[1] ?? 0);
}

$topProducts = array_slice($products, 0, 10);
$productsChartLabels = [];
$productsChartValues = [];
foreach ($topProducts as $p) {
    $productsChartLabels[] = (string) ($p['Sku'] ?? '');
    $productsChartValues[] = (float) ($p['TotalVendidoItem'] ?? 0);
}

$skuDailyMap = [];
if (is_array($skuData) && is_array($skuData['sales']['result'] ?? null)) {
    foreach ($skuData['sales']['result'] as $sale) {
        if (!is_array($sale)) {
            continue;
        }
        $dateRaw = (string) ($sale['DataAprovacao'] ?? '');
        $dateKey = $dateRaw !== '' ? substr($dateRaw, 0, 10) : '';
        if ($dateKey === '') {
            continue;
        }
        $skuDailyMap[$dateKey] = ($skuDailyMap[$dateKey] ?? 0) + (float) ($sale['Valor'] ?? 0);
    }
    ksort($skuDailyMap);
}
$skuChartLabels = array_keys($skuDailyMap);
$skuChartValues = array_values($skuDailyMap);
$comparisonMonths = is_array($comparison['months'] ?? null) ? $comparison['months'] : [];
$comparisonSeries = is_array($comparison['series'] ?? null) ? $comparison['series'] : [];
$growth = [];
foreach ($selectedYears as $idx => $year) {
    $prevIdx = $idx - 1;
    if ($prevIdx < 0) {
        continue;
    }
    $prevYear = (string) $selectedYears[$prevIdx];
    $currYear = (string) $year;
    $prevTotal = array_sum($comparisonSeries[$prevYear] ?? []);
    $currTotal = array_sum($comparisonSeries[$currYear] ?? []);
    $growth[$currYear] = $prevTotal > 0 ? (($currTotal - $prevTotal) / $prevTotal) * 100 : 0.0;
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
    .lexos-chart-wrap { margin-top:12px; border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:#fff; }
    .lexos-chart-wrap canvas { width:100%; max-height:320px; }
    .lexos-growth-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:10px; }
    .lexos-growth-card { border:1px solid #e5e7eb; border-radius:8px; padding:10px; background:#f8fafc; }
    .lexos-pagination { display:flex; gap:8px; justify-content:center; margin-top:10px; }
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
        <?php if ($channelsChartLabels): ?>
            <div class="lexos-chart-wrap">
                <canvas id="lexos-channel-chart"></canvas>
            </div>
        <?php endif; ?>
    </div>

    <div class="lexos-tab-content<?= $activeTab === 'comparison' ? ' active' : '' ?>" data-content="comparison">
        <h1>Comparação Anual</h1>
        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="lexos_tab" value="comparison">
            <input type="hidden" name="lexos_start" value="<?= htmlspecialchars($dStart) ?>">
            <input type="hidden" name="lexos_end" value="<?= htmlspecialchars($dEnd) ?>">
            <input type="hidden" name="lexos_search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="lexos_sku" value="<?= htmlspecialchars($sku) ?>">
            <label>Anos</label>
            <select name="lexos_years[]" multiple style="height:120px;">
                <?php foreach (['2023','2024','2025','2026'] as $y): ?>
                    <option value="<?= $y ?>" <?= in_array($y, $selectedYears, true) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Atualizar</button>
        </form>
        <div class="lexos-growth-grid">
            <?php foreach ($growth as $year => $val): ?>
                <div class="lexos-growth-card">
                    Crescimento <?= htmlspecialchars($year) ?><strong><?= ($val >= 0 ? '+' : '') . htmlspecialchars(number_format($val, 1, ',', '.')) ?>%</strong>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="lexos-chart-wrap">
            <canvas id="lexos-comparison-chart"></canvas>
        </div>
        <table>
            <thead>
            <tr>
                <th>Mês</th>
                <?php foreach ($selectedYears as $y): ?><th><?= htmlspecialchars((string) $y) ?></th><?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($comparisonMonths as $mi => $mn): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $mn) ?></td>
                    <?php foreach ($selectedYears as $y): ?>
                        <td>R$ <?= htmlspecialchars(number_format((float) (($comparisonSeries[(string) $y][$mi] ?? 0)), 2, ',', '.')) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="lexos-tab-content<?= $activeTab === 'products' ? ' active' : '' ?>" data-content="products">
        <h1>Produtos</h1>
        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="lexos_tab" value="products">
            <input type="hidden" name="lexos_start" value="<?= htmlspecialchars($dStart) ?>">
            <input type="hidden" name="lexos_end" value="<?= htmlspecialchars($dEnd) ?>">
            <input type="hidden" name="lexos_products_take" value="<?= htmlspecialchars((string) $productsPerPage) ?>">
            <label>Buscar SKU/Nome/EAN</label>
            <input type="text" name="lexos_search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="lexos_sku" value="<?= htmlspecialchars($sku) ?>">
            <button type="submit">Filtrar</button>
        </form>
        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="dashboard">
            <input type="hidden" name="lexos_tab" value="products">
            <input type="hidden" name="lexos_start" value="<?= htmlspecialchars($dStart) ?>">
            <input type="hidden" name="lexos_end" value="<?= htmlspecialchars($dEnd) ?>">
            <input type="hidden" name="lexos_search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="lexos_sku" value="<?= htmlspecialchars($sku) ?>">
            <label>Itens/página</label>
            <select name="lexos_products_take">
                <?php foreach ([10,20,50,100] as $takeOpt): ?>
                    <option value="<?= $takeOpt ?>" <?= $productsPerPage === $takeOpt ? 'selected' : '' ?>><?= $takeOpt ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Aplicar</button>
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
        <?php if ($productsChartLabels): ?>
            <div class="lexos-chart-wrap">
                <canvas id="lexos-products-chart"></canvas>
            </div>
        <?php endif; ?>
        <?php
            $productsTotalPages = max(1, (int) ceil($productsTotal / max(1, $productsPerPage)));
            $prevPage = max(1, $productsPage - 1);
            $nextPage = min($productsTotalPages, $productsPage + 1);
        ?>
        <div class="lexos-pagination">
            <?php if ($productsPage > 1): ?>
                <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=dashboard&lexos_tab=products&lexos_start=' . urlencode($dStart) . '&lexos_end=' . urlencode($dEnd) . '&lexos_search=' . urlencode($search) . '&lexos_sku=' . urlencode($sku) . '&lexos_products_take=' . urlencode((string) $productsPerPage) . '&lexos_products_page=' . urlencode((string) $prevPage))) ?>">Anterior</a>
            <?php endif; ?>
            <span>Página <?= htmlspecialchars((string) $productsPage) ?> de <?= htmlspecialchars((string) $productsTotalPages) ?></span>
            <?php if ($productsPage < $productsTotalPages): ?>
                <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=dashboard&lexos_tab=products&lexos_start=' . urlencode($dStart) . '&lexos_end=' . urlencode($dEnd) . '&lexos_search=' . urlencode($search) . '&lexos_sku=' . urlencode($sku) . '&lexos_products_take=' . urlencode((string) $productsPerPage) . '&lexos_products_page=' . urlencode((string) $nextPage))) ?>">Próxima</a>
            <?php endif; ?>
        </div>
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
            <?php
                $salesRows = is_array($skuData['sales']['result'] ?? null) ? $skuData['sales']['result'] : [];
                $stockRows = is_array($skuData['stock']['result'] ?? null) ? $skuData['stock']['result'] : [];
                $estoqueAtual = (float) (($stockRows[0]['Quantidade'] ?? 0));
                $totalFat = 0.0;
                $totalQt = 0.0;
                foreach ($salesRows as $sr) {
                    if (!is_array($sr)) { continue; }
                    $totalFat += (float) ($sr['Valor'] ?? 0);
                    $totalQt += (float) ($sr['Qtde'] ?? 0);
                }
                $daysSpan = max(1, (int) ((strtotime($dEnd) - strtotime($dStart)) / 86400) + 1);
                $mediaDia = $totalQt / $daysSpan;
                $cobertura = $mediaDia > 0 ? floor($estoqueAtual / $mediaDia) : null;
            ?>
            <div class="lexos-metrics" style="grid-template-columns:repeat(4,minmax(0,1fr));">
                <div class="lexos-metric">Estoque Atual<strong><?= htmlspecialchars(number_format($estoqueAtual, 0, ',', '.')) ?></strong></div>
                <div class="lexos-metric">Faturamento no Período<strong>R$ <?= htmlspecialchars(number_format($totalFat, 2, ',', '.')) ?></strong></div>
                <div class="lexos-metric">Vendas Médias / Dia<strong><?= htmlspecialchars(number_format($mediaDia, 2, ',', '.')) ?> un/dia</strong></div>
                <div class="lexos-metric">Cobertura de Estoque<strong><?= $cobertura === null ? 'N/A' : htmlspecialchars((string) $cobertura . ' dias') ?></strong></div>
            </div>
            <?php if ($skuChartLabels): ?>
                <div class="lexos-chart-wrap">
                    <canvas id="lexos-sku-chart"></canvas>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p>Informe um SKU para visualizar os dados.</p>
        <?php endif; ?>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    var tabs = document.querySelectorAll('#lexos-tabs .lexos-tab-btn');
    var contents = document.querySelectorAll('.lexos-tab-content');
    if (!tabs.length) return;
    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = btn.getAttribute('data-tab') || '';
            var url = new URL(window.location.href);
            url.searchParams.set('page', 'dashboard');
            url.searchParams.set('lexos_tab', target);
            window.location.href = url.toString();
        });
    });

    if (typeof Chart !== 'undefined') {
        var channelLabels = <?= json_encode($channelsChartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
        var channelValues = <?= json_encode($channelsChartValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
        var chCanvas = document.getElementById('lexos-channel-chart');
        if (chCanvas && channelLabels.length) {
            new Chart(chCanvas.getContext('2d'), {
                type: 'doughnut',
                data: { labels: channelLabels, datasets: [{ data: channelValues }] },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }

        var prodLabels = <?= json_encode($productsChartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
        var prodValues = <?= json_encode($productsChartValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
        var pCanvas = document.getElementById('lexos-products-chart');
        if (pCanvas && prodLabels.length) {
            new Chart(pCanvas.getContext('2d'), {
                type: 'bar',
                data: { labels: prodLabels, datasets: [{ label: 'Faturamento', data: prodValues, backgroundColor: 'rgba(37,99,235,.55)' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
        }

        var skuLabels = <?= json_encode($skuChartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
        var skuValues = <?= json_encode($skuChartValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
        var sCanvas = document.getElementById('lexos-sku-chart');
        if (sCanvas && skuLabels.length) {
            new Chart(sCanvas.getContext('2d'), {
                type: 'line',
                data: { labels: skuLabels, datasets: [{ label: 'Faturamento diário', data: skuValues, borderColor: '#059669', backgroundColor: 'rgba(5,150,105,.15)', fill: true, tension: .3 }] },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }

        var cmpMonths = <?= json_encode($comparisonMonths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
        var cmpSeries = <?= json_encode($comparisonSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
        var cmpCanvas = document.getElementById('lexos-comparison-chart');
        if (cmpCanvas && cmpMonths.length) {
            var colors = ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6'];
            var datasets = Object.keys(cmpSeries).map(function (year, idx) {
                return {
                    label: year,
                    data: cmpSeries[year] || [],
                    borderColor: colors[idx % colors.length],
                    backgroundColor: colors[idx % colors.length] + '22',
                    tension: 0.35,
                    fill: false
                };
            });
            new Chart(cmpCanvas.getContext('2d'), {
                type: 'line',
                data: { labels: cmpMonths, datasets: datasets },
                options: { responsive: true, maintainAspectRatio: false }
            });
        }
    }
})();
</script>
