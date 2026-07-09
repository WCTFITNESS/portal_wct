<?php

declare(strict_types=1);

$ml = $app['mlDashboardService'];
$apiConfig = $app['settingsRepository']->getApiConfig();
$mlToken = $app['tokenRepository']->getLatestToken();
$mlReady = $apiConfig
    && trim((string) ($apiConfig['seller_id'] ?? '')) !== ''
    && is_array($mlToken)
    && trim((string) ($mlToken['access_token'] ?? '')) !== '';

$today = new DateTimeImmutable('now');
$monthStart = $today->modify('first day of this month');
$dStart = trim((string) ($_GET['ml_start'] ?? $monthStart->format('Y-m-d')));
$dEnd = trim((string) ($_GET['ml_end'] ?? $today->format('Y-m-d')));
$search = trim((string) ($_GET['ml_search'] ?? ''));
$sku = trim((string) ($_GET['ml_sku'] ?? ''));
$activeTab = trim((string) ($_GET['ml_tab'] ?? 'dashboard'));
if (!in_array($activeTab, ['dashboard', 'comparison', 'products', 'sku-analysis'], true)) {
    $activeTab = 'dashboard';
}

$metrics = null;
$listingTypes = [];
$products = [];
$productsTotal = 0;
$productsTruncated = false;
$productsPage = max(1, (int) ($_GET['ml_products_page'] ?? 1));
$productsPerPage = max(10, min(100, (int) ($_GET['ml_products_take'] ?? 20)));
$skuData = null;
$mlError = null;
$truncatedNote = '';

$selectedYears = $_GET['ml_years'] ?? ['2024', '2025', '2026'];
if (!is_array($selectedYears) || $selectedYears === []) {
    $selectedYears = ['2024', '2025', '2026'];
}
$selectedYears = array_values(array_map(static fn (mixed $y): string => (string) $y, $selectedYears));
$comparison = ['months' => [], 'series' => []];

if ($mlReady) {
    try {
        if ($activeTab === 'dashboard') {
            $metrics = $ml->getDashboardMetrics($dStart, $dEnd);
            $listingTypes = is_array($metrics['listing_types'] ?? null) ? $metrics['listing_types'] : [];
            if (!empty($metrics['truncated'])) {
                $truncatedNote = 'Exibindo amostra de até 2.000 pedidos pagos (total na API: ' . (int) ($metrics['total_api'] ?? 0) . ').';
            }
        } elseif ($activeTab === 'comparison') {
            $comparison = $ml->getComparisonData($selectedYears);
        } elseif ($activeTab === 'products') {
            $productsResp = $ml->getProducts($dStart, $dEnd, $search, $productsPerPage, ($productsPage - 1) * $productsPerPage);
            $products = is_array($productsResp['items'] ?? null) ? $productsResp['items'] : [];
            $productsTotal = (int) ($productsResp['count'] ?? count($products));
            $productsTruncated = (bool) ($productsResp['truncated'] ?? false);
            if ($productsTruncated) {
                $truncatedNote = 'Ranking calculado sobre amostra de pedidos pagos (total na API: ' . (int) ($productsResp['total_api'] ?? 0) . ').';
            }
        } elseif ($activeTab === 'sku-analysis' && $sku !== '') {
            $skuData = $ml->getSkuAnalysis($sku, $dStart, $dEnd);
        }
    } catch (Throwable $e) {
        $mlError = $e->getMessage();
    }
}

$listingChartLabels = [];
$listingChartValues = [];
foreach ($listingTypes as $row) {
    $listingChartLabels[] = (string) ($row[0] ?? '');
    $listingChartValues[] = (float) ($row[1] ?? 0);
}

$topProducts = array_slice($products, 0, 10);
$productsChartLabels = [];
$productsChartValues = [];
foreach ($topProducts as $p) {
    $productsChartLabels[] = (string) ($p['Sku'] ?? '');
    $productsChartValues[] = (float) ($p['TotalVendidoItem'] ?? 0);
}

$skuDailyRevenue = [];
$skuDailyQty = [];
if (is_array($skuData) && is_array($skuData['sales']['result'] ?? null)) {
    foreach ($skuData['sales']['result'] as $sale) {
        if (!is_array($sale)) {
            continue;
        }
        $dateKey = substr((string) ($sale['DataAprovacao'] ?? ''), 0, 10);
        if ($dateKey === '') {
            continue;
        }
        $skuDailyRevenue[$dateKey] = ($skuDailyRevenue[$dateKey] ?? 0) + (float) ($sale['Valor'] ?? 0);
        $skuDailyQty[$dateKey] = ($skuDailyQty[$dateKey] ?? 0) + (float) ($sale['Qtde'] ?? 0);
    }
    ksort($skuDailyRevenue);
    ksort($skuDailyQty);
}
$skuDateKeys = array_unique(array_merge(array_keys($skuDailyRevenue), array_keys($skuDailyQty)));
sort($skuDateKeys);
$skuChartLabels = $skuDateKeys;
$skuChartValues = [];
$skuChartQtyValues = [];
foreach ($skuDateKeys as $d) {
    $skuChartValues[] = (float) ($skuDailyRevenue[$d] ?? 0);
    $skuChartQtyValues[] = (float) ($skuDailyQty[$d] ?? 0);
}

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

$mlJsonEmbed = static function (mixed $data): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE;
    $out = json_encode($data, $flags);

    return $out === false ? '[]' : $out;
};

$mlTabUrl = static function (string $tabId) use ($baseUrl, $dStart, $dEnd, $search, $sku, $selectedYears, $productsPerPage, $productsPage): string {
    $query = [
        'page' => 'ml-dashboard',
        'ml_tab' => $tabId,
        'ml_start' => $dStart,
        'ml_end' => $dEnd,
        'ml_search' => $search,
        'ml_sku' => $sku,
    ];
    if ($tabId === 'products') {
        $query['ml_products_take'] = (string) $productsPerPage;
        $query['ml_products_page'] = (string) $productsPage;
    }
    $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    if ($tabId === 'comparison') {
        foreach (array_values($selectedYears) as $y) {
            $qs .= '&ml_years[]=' . rawurlencode((string) $y);
        }
    }

    return portal_wct_public_path($baseUrl, 'index.php?' . $qs);
};
?>
<style>
    .ml-dash-tabs { display:flex; gap:6px; border-bottom:1px solid #e5e7eb; margin-bottom:14px; flex-wrap:wrap; }
    .ml-dash-tab-btn { margin:0; background:#f8fafc; color:#334155; border:1px solid #e2e8f0; border-bottom:none; border-radius:8px 8px 0 0; padding:10px 14px; text-transform:none; letter-spacing:0; font-size:.9rem; cursor:pointer; }
    a.ml-dash-tab-btn { text-decoration:none; display:inline-block; box-sizing:border-box; }
    .ml-dash-tab-btn.active { background:#fff; color:#2563eb; border-color:#cbd5e1; font-weight:700; }
    .ml-dash-tab-content { display:none; }
    .ml-dash-tab-content.active { display:block; }
    .ml-dash-header { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:12px; }
    .ml-dash-header h1 { margin:0; }
    .ml-dash-date-inline { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    .ml-dash-date-inline input { width:auto; min-width:145px; }
    .ml-dash-metrics { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin:10px 0 14px 0; }
    .ml-dash-metric { border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:#f8fafc; }
    .ml-dash-metric strong { display:block; font-size:1.05rem; margin-top:4px; }
    .ml-dash-chart-wrap { margin-top:12px; border:1px solid #e5e7eb; border-radius:8px; padding:18px 16px 20px; background:#fff; }
    .ml-dash-donut-center { display:flex; flex-direction:column; align-items:center; }
    .ml-dash-donut-visual { position:relative; width:min(400px, 94vw); height:min(400px, 94vw); margin:0 auto; }
    .ml-dash-donut-visual canvas { display:block; width:100% !important; height:100% !important; }
    .ml-dash-pie-list { width:100%; max-width:520px; margin:16px 0 0 0; padding:0; list-style:none; }
    .ml-dash-pie-list li { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:.8rem; color:#334155; margin:4px 0; }
    .ml-dash-dot { width:10px; height:10px; border-radius:999px; display:inline-block; margin-right:6px; vertical-align:middle; }
    .ml-dash-growth-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:10px; }
    .ml-dash-growth-card { border:1px solid #e5e7eb; border-radius:8px; padding:10px; background:#f8fafc; }
    .ml-dash-pagination { display:flex; gap:8px; justify-content:center; margin-top:10px; }
    .ml-dash-note { font-size:.85rem; color:#64748b; margin:.5rem 0 1rem; }
    .ml-dash-sku-toolbar { margin-bottom:16px; }
    .ml-dash-sku-filter-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px 18px; }
    .ml-dash-sku-filter-item { display:flex; flex-direction:column; gap:4px; }
    .ml-dash-sku-chart-host { position:relative; width:100%; height:min(380px, 70vh); margin-top:6px; }
    .ml-dash-metric-alert { color:#dc2626; font-weight:800; }
</style>

<section class="card">
    <?php if (!$mlReady): ?>
        <div class="msg err">Configure <strong>Configuração API → Mercado Livre</strong> (App ID, Secret, Seller ID e token OAuth) para usar o Dashboard ML.</div>
    <?php elseif ($mlError): ?>
        <div class="msg err">Erro ML: <?= htmlspecialchars($mlError) ?></div>
    <?php endif; ?>
    <?php if ($truncatedNote !== ''): ?>
        <p class="ml-dash-note"><?= htmlspecialchars($truncatedNote) ?></p>
    <?php endif; ?>

    <nav class="ml-dash-tabs">
        <a class="ml-dash-tab-btn<?= $activeTab === 'dashboard' ? ' active' : '' ?>" href="<?= htmlspecialchars($mlTabUrl('dashboard'), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a>
        <a class="ml-dash-tab-btn<?= $activeTab === 'comparison' ? ' active' : '' ?>" href="<?= htmlspecialchars($mlTabUrl('comparison'), ENT_QUOTES, 'UTF-8') ?>">Comparação Anual</a>
        <a class="ml-dash-tab-btn<?= $activeTab === 'products' ? ' active' : '' ?>" href="<?= htmlspecialchars($mlTabUrl('products'), ENT_QUOTES, 'UTF-8') ?>">Produtos</a>
        <a class="ml-dash-tab-btn<?= $activeTab === 'sku-analysis' ? ' active' : '' ?>" href="<?= htmlspecialchars($mlTabUrl('sku-analysis'), ENT_QUOTES, 'UTF-8') ?>">Análise de SKU</a>
    </nav>

    <div class="ml-dash-tab-content<?= $activeTab === 'dashboard' ? ' active' : '' ?>">
        <div class="ml-dash-header">
            <h1>Dashboard Mercado Livre</h1>
            <form method="get" class="ml-dash-date-inline">
                <input type="hidden" name="page" value="ml-dashboard">
                <input type="hidden" name="ml_tab" value="dashboard">
                <label for="ml-start">Período:</label>
                <input id="ml-start" type="date" name="ml_start" value="<?= htmlspecialchars($dStart) ?>">
                <span>até</span>
                <input type="date" name="ml_end" value="<?= htmlspecialchars($dEnd) ?>">
                <button type="submit">Aplicar</button>
            </form>
        </div>
        <?php if (is_array($metrics)): ?>
            <div class="ml-dash-metrics">
                <div class="ml-dash-metric">Faturamento<strong>R$ <?= htmlspecialchars(number_format((float) ($metrics['faturamento'] ?? 0), 2, ',', '.')) ?></strong></div>
                <div class="ml-dash-metric">Pedidos pagos<strong><?= htmlspecialchars((string) ($metrics['pedidos'] ?? 0)) ?></strong></div>
                <div class="ml-dash-metric">Ticket médio<strong>R$ <?= htmlspecialchars(number_format((float) ($metrics['ticket_medio'] ?? 0), 2, ',', '.')) ?></strong></div>
            </div>
        <?php endif; ?>

        <h2 style="margin-top:1rem;font-size:1.1rem">Faturamento por tipo de anúncio</h2>
        <table>
            <thead><tr><th>Tipo</th><th>Faturamento</th></tr></thead>
            <tbody>
            <?php if (!$listingTypes): ?>
                <tr><td colspan="2">Sem pedidos pagos no período.</td></tr>
            <?php endif; ?>
            <?php foreach ($listingTypes as $row): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($row[0] ?? '')) ?></td>
                    <td>R$ <?= htmlspecialchars(number_format((float) ($row[1] ?? 0), 2, ',', '.')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($listingTypes): ?>
            <?php $palette = ['#ffe600','#3483fa','#00a650','#f73','#a64bf4']; $listingTotal = array_sum($listingChartValues); ?>
            <div class="ml-dash-chart-wrap">
                <div class="ml-dash-donut-center">
                    <div class="ml-dash-donut-visual"><canvas id="ml-listing-chart"></canvas></div>
                    <ul class="ml-dash-pie-list">
                        <?php foreach ($listingTypes as $i => $row): ?>
                            <?php
                                $val = (float) ($row[1] ?? 0);
                                $pct = $listingTotal > 0 ? ($val / $listingTotal) * 100 : 0;
                                $fill = $palette[$i % count($palette)];
                            ?>
                            <li>
                                <span><span class="ml-dash-dot" style="background:<?= htmlspecialchars($fill, ENT_QUOTES, 'UTF-8') ?>"></span><?= htmlspecialchars((string) ($row[0] ?? '')) ?></span>
                                <strong><?= htmlspecialchars(number_format($pct, 1, ',', '.'), ENT_QUOTES, 'UTF-8') ?>%</strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="ml-dash-tab-content<?= $activeTab === 'comparison' ? ' active' : '' ?>">
        <h1>Comparação anual (pedidos pagos ML)</h1>
        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="ml-dashboard">
            <input type="hidden" name="ml_tab" value="comparison">
            <label>Anos (Ctrl+clique)</label>
            <select name="ml_years[]" multiple style="height:120px;">
                <?php foreach (['2023','2024','2025','2026'] as $y): ?>
                    <option value="<?= $y ?>" <?= in_array($y, $selectedYears, true) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Atualizar</button>
        </form>
        <div class="ml-dash-growth-grid">
            <?php foreach ($growth as $year => $val): ?>
                <div class="ml-dash-growth-card">
                    vs. ano anterior <?= htmlspecialchars((string) $year) ?>
                    <strong><?= ($val >= 0 ? '+' : '') . htmlspecialchars(number_format((float) $val, 1, ',', '.')) ?>%</strong>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="ml-dash-chart-wrap"><canvas id="ml-comparison-chart" height="120"></canvas></div>
        <table>
            <thead>
            <tr><th>Mês</th><?php foreach ($selectedYears as $y): ?><th><?= htmlspecialchars((string) $y) ?></th><?php endforeach; ?></tr>
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

    <div class="ml-dash-tab-content<?= $activeTab === 'products' ? ' active' : '' ?>">
        <h1>Produtos vendidos (ML)</h1>
        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="ml-dashboard">
            <input type="hidden" name="ml_tab" value="products">
            <input type="hidden" name="ml_start" value="<?= htmlspecialchars($dStart) ?>">
            <input type="hidden" name="ml_end" value="<?= htmlspecialchars($dEnd) ?>">
            <input type="hidden" name="ml_products_take" value="<?= htmlspecialchars((string) $productsPerPage) ?>">
            <label>Buscar SKU / MLB / nome</label>
            <input type="text" name="ml_search" value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Filtrar</button>
        </form>
        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="ml-dashboard">
            <input type="hidden" name="ml_tab" value="products">
            <input type="hidden" name="ml_start" value="<?= htmlspecialchars($dStart) ?>">
            <input type="hidden" name="ml_end" value="<?= htmlspecialchars($dEnd) ?>">
            <input type="hidden" name="ml_search" value="<?= htmlspecialchars($search) ?>">
            <label>Período</label>
            <input type="date" name="ml_start" value="<?= htmlspecialchars($dStart) ?>">
            <input type="date" name="ml_end" value="<?= htmlspecialchars($dEnd) ?>">
            <label>Itens/página</label>
            <select name="ml_products_take">
                <?php foreach ([10, 20, 50, 100] as $takeOpt): ?>
                    <option value="<?= $takeOpt ?>" <?= $productsPerPage === $takeOpt ? 'selected' : '' ?>><?= $takeOpt ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Aplicar</button>
        </form>
        <table>
            <thead>
            <tr><th>SKU</th><th>MLB</th><th>Nome</th><th>Faturamento</th><th>Quantidade</th></tr>
            </thead>
            <tbody>
            <?php if (!$products): ?>
                <tr><td colspan="5">Nenhum produto no período.</td></tr>
            <?php endif; ?>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($p['Sku'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($p['Mlb'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($p['Nome'] ?? '')) ?></td>
                    <td>R$ <?= htmlspecialchars(number_format((float) ($p['TotalVendidoItem'] ?? 0), 2, ',', '.')) ?></td>
                    <td><?= htmlspecialchars(number_format((float) ($p['TotalUnidadesVendidas'] ?? 0), 0, ',', '.')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($productsChartLabels): ?>
            <div class="ml-dash-chart-wrap"><canvas id="ml-products-chart" height="100"></canvas></div>
        <?php endif; ?>
        <?php
            $productsTotalPages = max(1, (int) ceil($productsTotal / max(1, $productsPerPage)));
            $prevPage = max(1, $productsPage - 1);
            $nextPage = min($productsTotalPages, $productsPage + 1);
        ?>
        <div class="ml-dash-pagination">
            <?php if ($productsPage > 1): ?>
                <a href="<?= htmlspecialchars($mlTabUrl('products') . '&ml_products_page=' . $prevPage) ?>">Anterior</a>
            <?php endif; ?>
            <span>Página <?= (int) $productsPage ?> de <?= (int) $productsTotalPages ?> (<?= (int) $productsTotal ?> itens)</span>
            <?php if ($productsPage < $productsTotalPages): ?>
                <a href="<?= htmlspecialchars($mlTabUrl('products') . '&ml_products_page=' . $nextPage) ?>">Próxima</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="ml-dash-tab-content<?= $activeTab === 'sku-analysis' ? ' active' : '' ?>">
        <h1>Análise de SKU (ML)</h1>
        <div class="ml-dash-sku-toolbar">
            <form method="get" class="ml-dash-sku-filter-row">
                <input type="hidden" name="page" value="ml-dashboard">
                <input type="hidden" name="ml_tab" value="sku-analysis">
                <div class="ml-dash-sku-filter-item">
                    <label for="ml-sku">SKU</label>
                    <input id="ml-sku" type="text" name="ml_sku" value="<?= htmlspecialchars($sku) ?>" placeholder="SKU do anúncio">
                </div>
                <div class="ml-dash-sku-filter-item">
                    <label>De</label>
                    <input type="date" name="ml_start" value="<?= htmlspecialchars($dStart) ?>">
                </div>
                <div class="ml-dash-sku-filter-item">
                    <label>Até</label>
                    <input type="date" name="ml_end" value="<?= htmlspecialchars($dEnd) ?>">
                </div>
                <button type="submit">Buscar</button>
            </form>
        </div>
        <?php if (is_array($skuData)): ?>
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
                $cobertura = $mediaDia > 0 && $estoqueAtual > 0 ? (int) floor($estoqueAtual / $mediaDia) : null;
                $skuNome = trim((string) ($skuData['summary']['title'] ?? ($stockRows[0]['Nome'] ?? '')));
            ?>
            <?php if ($skuNome !== ''): ?>
                <p style="font-weight:700;margin-bottom:12px"><?= htmlspecialchars($skuNome) ?></p>
            <?php endif; ?>
            <div class="ml-dash-metrics" style="grid-template-columns:repeat(4,minmax(0,1fr));">
                <div class="ml-dash-metric">Estoque ML<strong><?= $estoqueAtual > 0 ? htmlspecialchars(number_format($estoqueAtual, 0, ',', '.')) : '—' ?></strong></div>
                <div class="ml-dash-metric">Faturamento<strong>R$ <?= htmlspecialchars(number_format($totalFat, 2, ',', '.')) ?></strong></div>
                <div class="ml-dash-metric">Média/dia<strong><?= htmlspecialchars(number_format($mediaDia, 2, ',', '.')) ?> un</strong></div>
                <div class="ml-dash-metric">Cobertura<strong><?php if ($cobertura === null): ?>N/A<?php else: ?><span class="ml-dash-metric-alert"><?= (int) $cobertura ?> dias</span><?php endif; ?></strong></div>
            </div>
            <?php if ($skuChartLabels !== []): ?>
                <div class="ml-dash-chart-wrap">
                    <h2 style="margin:0 0 10px;font-size:1.05rem">Vendas diárias</h2>
                    <div class="ml-dash-sku-chart-host"><canvas id="ml-sku-chart"></canvas></div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p style="color:#64748b">Informe o SKU e clique em <strong>Buscar</strong>.</p>
        <?php endif; ?>
    </div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    var listingLabels = <?= $mlJsonEmbed($listingChartLabels) ?>;
    var listingValues = <?= $mlJsonEmbed($listingChartValues) ?>;
    var listingCanvas = document.getElementById('ml-listing-chart');
    if (listingCanvas && listingLabels.length) {
        new Chart(listingCanvas.getContext('2d'), {
            type: 'doughnut',
            data: { labels: listingLabels, datasets: [{ data: listingValues, backgroundColor: ['#ffe600','#3483fa','#00a650','#ff7733','#a64bf4'], borderWidth: 1 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '58%', plugins: { legend: { display: false } } }
        });
    }
    var prodLabels = <?= $mlJsonEmbed($productsChartLabels) ?>;
    var prodValues = <?= $mlJsonEmbed($productsChartValues) ?>;
    var prodCanvas = document.getElementById('ml-products-chart');
    if (prodCanvas && prodLabels.length) {
        new Chart(prodCanvas.getContext('2d'), {
            type: 'bar',
            data: { labels: prodLabels, datasets: [{ label: 'Faturamento', data: prodValues, backgroundColor: '#3483fa' }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
        });
    }
    var skuLabels = <?= $mlJsonEmbed($skuChartLabels) ?>;
    var skuValues = <?= $mlJsonEmbed($skuChartValues) ?>;
    var skuCanvas = document.getElementById('ml-sku-chart');
    if (skuCanvas && skuLabels.length) {
        new Chart(skuCanvas.getContext('2d'), {
            type: 'line',
            data: { labels: skuLabels, datasets: [{ label: 'R$', data: skuValues, borderColor: '#3483fa', tension: 0.2, fill: false }] },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
        });
    }
    var cmpMonths = <?= $mlJsonEmbed($comparisonMonths) ?>;
    var cmpSeries = <?= $mlJsonEmbed($comparisonSeries) ?>;
    var cmpCanvas = document.getElementById('ml-comparison-chart');
    if (cmpCanvas && cmpMonths.length && Object.keys(cmpSeries).length) {
        var colors = ['#3483fa','#00a650','#ffe600','#f23d4f','#a64bf4'];
        var datasets = [];
        var ci = 0;
        for (var year in cmpSeries) {
            if (!Object.prototype.hasOwnProperty.call(cmpSeries, year)) continue;
            datasets.push({ label: year, data: cmpSeries[year], borderColor: colors[ci % colors.length], backgroundColor: colors[ci % colors.length] + '33', tension: 0.2, fill: false });
            ci++;
        }
        new Chart(cmpCanvas.getContext('2d'), {
            type: 'line',
            data: { labels: cmpMonths, datasets: datasets },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
    }
})();
</script>
