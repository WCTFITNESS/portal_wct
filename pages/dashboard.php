<?php

declare(strict_types=1);

/** Rota do portal: {@code dashboard} (Lexos) ou {@code ml-dashboard} (Mercado Livre). */
$dashboardPageId = $dashboardPageId ?? 'dashboard';

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

/**
 * Metabase pode devolver linhas como lista [canal, valor] ou com chaves nomeadas.
 *
 * @return array{0: string, 1: float}|null
 */
$normalizeChannelRow = static function (mixed $row): ?array {
    if (!is_array($row)) {
        return null;
    }
    if ($row !== [] && array_keys($row) === range(0, count($row) - 1) && count($row) >= 2) {
        return [(string) $row[0], (float) $row[1]];
    }
    $vals = array_values($row);
    if (count($vals) >= 2) {
        return [(string) $vals[0], (float) $vals[1]];
    }

    return null;
};

$selectedYears = $_GET['lexos_years'] ?? ['2023', '2024', '2025', '2026'];
if (!is_array($selectedYears) || $selectedYears === []) {
    $selectedYears = ['2023', '2024', '2025', '2026'];
}
$selectedYears = array_values(array_map(static fn (mixed $y): string => (string) $y, $selectedYears));
$comparison = ['months' => [], 'series' => []];

try {
    if ($activeTab === 'dashboard') {
        $metrics = $lexos->getDashboardMetrics($dStart, $dEnd);
        $channelsRaw = is_array($metrics['canais'] ?? null) ? $metrics['canais'] : [];
        $channels = [];
        foreach ($channelsRaw as $r) {
            $pair = $normalizeChannelRow($r);
            if ($pair !== null) {
                $channels[] = $pair;
            }
        }
    } elseif ($activeTab === 'comparison') {
        $comparison = $lexos->getComparisonData($selectedYears);
    } elseif ($app['lexosCredentialsService']->hasHubToken()) {
        if ($activeTab === 'products') {
            $productsResp = $lexos->getProducts($dStart, $dEnd, $search, $productsPerPage, ($productsPage - 1) * $productsPerPage);
            $products = is_array($productsResp['items'] ?? null) ? $productsResp['items'] : [];
            $productsTotal = (int) ($productsResp['count'] ?? count($products));
        } elseif ($activeTab === 'sku-analysis' && $sku !== '') {
            $skuData = $lexos->getSkuAnalysis($sku, $dStart, $dEnd);
        }
    }
} catch (Throwable $e) {
    $lexosError = $e->getMessage();
}

/** ML Dashboard: verifica sessão Lexos Hub (Token Hub válido na WebAPI). */
$lexosHubLoggedIn = true;
$lexosHubConnectReason = '';
$lexosOpenHubWindow = false;
$lexosHubConfigUrl = portal_wct_public_path($baseUrl, 'index.php?page=api-config&api_tab=lexos');

if ($dashboardPageId === 'ml-dashboard') {
    $lexosHubLoggedIn = false;
    $lexosCreds = $app['lexosCredentialsService'];
    if (!$lexosCreds->hasHubToken()) {
        $lexosHubConnectReason = 'Token Hub não configurado no portal.';
        $lexosOpenHubWindow = true;
    } elseif (in_array($activeTab, ['products', 'sku-analysis'], true)) {
        try {
            $hubProbe = $app['lexosHubApiClient']->probeHubApi();
            $lexosHubLoggedIn = (bool) ($hubProbe['hub_ok'] ?? false);
            if (!$lexosHubLoggedIn) {
                $lexosHubConnectReason = (string) ($hubProbe['hub_error'] ?? 'Token Hub expirado ou inválido.');
                $lexosOpenHubWindow = true;
            }
        } catch (Throwable $hubEx) {
            $lexosHubConnectReason = $hubEx->getMessage();
            $lexosOpenHubWindow = true;
        }
    } else {
        $lexosHubLoggedIn = true;
    }
}

$channelsChartLabels = [];
$channelsChartValues = [];
foreach ($channels as $row) {
    $channelsChartLabels[] = (string) ($row[0] ?? '');
    $v = (float) ($row[1] ?? 0);
    $channelsChartValues[] = $v;
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
        $dateRaw = (string) ($sale['DataAprovacao'] ?? '');
        $dateKey = $dateRaw !== '' ? substr($dateRaw, 0, 10) : '';
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

/** Evita </script> e UTF-8 inválido quebrarem o JS embutido. */
$lexosJsonEmbed = static function (mixed $data): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE;
    $out = json_encode($data, $flags);

    return $out === false ? '[]' : $out;
};

/** Troca de aba por link real (GET) — não depende de JS nem de URLSearchParams. */
$lexosTabUrl = static function (string $tabId) use ($baseUrl, $dashboardPageId, $dStart, $dEnd, $search, $sku, $selectedYears, $productsPerPage, $productsPage): string {
    $query = [
        'page' => $dashboardPageId,
        'lexos_tab' => $tabId,
        'lexos_start' => $dStart,
        'lexos_end' => $dEnd,
        'lexos_search' => $search,
        'lexos_sku' => $sku,
    ];
    if ($tabId === 'products') {
        $query['lexos_products_take'] = (string) $productsPerPage;
        $query['lexos_products_page'] = (string) $productsPage;
    }
    $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    if ($tabId === 'comparison') {
        foreach (array_values($selectedYears) as $y) {
            $qs .= '&lexos_years[]=' . rawurlencode((string) $y);
        }
    }

    return portal_wct_public_path($baseUrl, 'index.php?' . $qs);
};
?>
<style>
    .lexos-tabs { display:flex; gap:6px; border-bottom:1px solid #e5e7eb; margin-bottom:14px; flex-wrap:wrap; }
    .lexos-tab-btn { margin:0; background:#f8fafc; color:#334155; border:1px solid #e2e8f0; border-bottom:none; border-radius:8px 8px 0 0; padding:10px 14px; text-transform:none; letter-spacing:0; font-size:.9rem; cursor:pointer; }
    a.lexos-tab-btn { text-decoration:none; display:inline-block; box-sizing:border-box; }
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
    .lexos-chart-wrap { margin-top:12px; border:1px solid #e5e7eb; border-radius:8px; padding:18px 16px 20px; background:#fff; }
    .lexos-donut-center { display:flex; flex-direction:column; align-items:center; }
    .lexos-donut-visual { position:relative; width:min(400px, 94vw); height:min(400px, 94vw); margin:0 auto; flex-shrink:0; }
    .lexos-donut-visual canvas { display:block; width:100% !important; height:100% !important; max-height:none !important; }
    .lexos-pie-list { width:100%; max-width:520px; margin:16px 0 0 0; padding:0; list-style:none; }
    .lexos-pie-list li { display:flex; align-items:center; justify-content:space-between; gap:8px; font-size:.8rem; color:#334155; margin:4px 0; }
    .lexos-dot { width:10px; height:10px; border-radius:999px; display:inline-block; margin-right:6px; vertical-align:middle; }
    .lexos-growth-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:10px; }
    .lexos-growth-card { border:1px solid #e5e7eb; border-radius:8px; padding:10px; background:#f8fafc; }
    .lexos-pagination { display:flex; gap:8px; justify-content:center; margin-top:10px; }
    .lexos-sku-toolbar { margin-bottom:16px; }
    .lexos-sku-filter-row { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px 18px; }
    .lexos-sku-filter-item { display:flex; flex-direction:column; gap:4px; min-width:0; }
    .lexos-sku-filter-row .lexos-sku-filter-item label { margin-top:0; font-size:.82rem; }
    .lexos-sku-filter-row input[type="date"],
    .lexos-sku-filter-row input[type="text"] { width:auto; min-width:140px; max-width:100%; margin-top:0; }
    .lexos-sku-filter-row button { margin-top:0; }
    .lexos-sku-product-bar {
        margin:14px 0 16px 0; padding:12px 14px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px;
        font-size:.95rem; font-weight:700; letter-spacing:.02em; color:#0f172a;
    }
    .lexos-sku-chart-block h2 { margin:0 0 10px 0; font-size:1.1rem; color:#1e293b; }
    .lexos-sku-chart-host { position:relative; width:100%; height:min(380px, 70vh); margin-top:6px; }
    .lexos-metric-alert { color:#dc2626; font-weight:800; }
    .lexos-products-header { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; margin-bottom:12px; }
    .lexos-products-header h1 { margin:0; }
    .lexos-products-filters { display:flex; flex-wrap:wrap; align-items:flex-end; gap:12px 18px; margin-bottom:14px; padding:12px; border:1px solid #e5e7eb; border-radius:8px; background:#f8fafc; }
    .lexos-products-filters .lexos-filter-item { display:flex; flex-direction:column; gap:4px; min-width:0; }
    .lexos-products-filters label { font-size:.82rem; margin:0; }
    .lexos-products-filters input, .lexos-products-filters select { width:auto; min-width:140px; margin:0; }
    .lexos-products-filters .lexos-filter-search { flex:1 1 220px; min-width:180px; }
    a.btn-export-xlsx { display:inline-block; padding:8px 14px; background:#3483fa; color:#fff !important; border-radius:6px; text-decoration:none; font-size:.88rem; font-weight:600; }
    a.btn-export-xlsx:hover { background:#2968c8; }
    .lexos-hub-connect-banner {
        margin-bottom:14px; padding:14px 16px; border:1px solid #fcd34d; border-radius:8px;
        background:#fffbeb; color:#92400e; font-size:.9rem;
    }
    .lexos-hub-connect-banner strong { color:#78350f; }
    .lexos-hub-connect-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; align-items:center; }
    .lexos-hub-connect-actions button, .lexos-hub-connect-actions a.lexos-hub-btn {
        margin:0; padding:8px 14px; border-radius:6px; font-size:.88rem; font-weight:600; cursor:pointer;
        text-decoration:none; display:inline-block; border:none;
    }
    .lexos-hub-connect-actions button, a.lexos-hub-btn-primary {
        background:#3483fa; color:#fff !important;
    }
    .lexos-hub-connect-actions a.lexos-hub-btn-secondary {
        background:#fff; color:#1e40af !important; border:1px solid #93c5fd;
    }
</style>

<section class="card">
    <?php
    $lexosNeedsHubToken = in_array($activeTab, ['products', 'sku-analysis'], true);
    ?>
    <?php if ($lexosOpenHubWindow): ?>
        <div class="lexos-hub-connect-banner" id="lexos-hub-connect-banner">
            <strong>Conexão com Lexos Hub necessária</strong>
            <p style="margin:.35rem 0 0">
                <?= htmlspecialchars($lexosHubConnectReason !== '' ? $lexosHubConnectReason : 'Faça login no Lexos Hub para usar Produtos e Análise de SKU.', ENT_QUOTES, 'UTF-8') ?>
                Abrimos (ou tentamos abrir) o Hub em uma nova janela. Após logar, use o favorito
                <strong>Capturar Token Hub → Portal</strong> em Configuração API → Lexos.
            </p>
            <div class="lexos-hub-connect-actions">
                <button type="button" class="lexos-hub-btn-primary" id="lexos-hub-open-btn">Abrir Lexos Hub</button>
                <a class="lexos-hub-btn lexos-hub-btn-secondary" href="<?= htmlspecialchars($lexosHubConfigUrl, ENT_QUOTES, 'UTF-8') ?>">Configurar Token Hub</a>
            </div>
            <p id="lexos-hub-popup-hint" style="margin:.5rem 0 0;font-size:.82rem;display:none;color:#b45309">
                Se a janela não abriu, o navegador pode ter bloqueado o pop-up — clique em <strong>Abrir Lexos Hub</strong>.
            </p>
        </div>
    <?php endif; ?>
    <?php if ($lexosNeedsHubToken && !$app['lexosCredentialsService']->hasHubToken() && !$lexosOpenHubWindow): ?>
        <div class="msg err">Configure o <strong>Token Hub (Dashboard)</strong> em Configuração API → Lexos (localStorage <code>access_token</code> de app-hub.lexos.com.br).</div>
    <?php elseif ($lexosError): ?>
        <div class="msg err">Erro Lexos: <?= htmlspecialchars($lexosError) ?></div>
    <?php endif; ?>

    <nav class="lexos-tabs" id="lexos-tabs">
        <a class="lexos-tab-btn<?= $activeTab === 'dashboard' ? ' active' : '' ?>" href="<?= htmlspecialchars($lexosTabUrl('dashboard'), ENT_QUOTES, 'UTF-8') ?>">Dashboard Atual</a>
        <a class="lexos-tab-btn<?= $activeTab === 'comparison' ? ' active' : '' ?>" href="<?= htmlspecialchars($lexosTabUrl('comparison'), ENT_QUOTES, 'UTF-8') ?>">Comparação Anual</a>
        <a class="lexos-tab-btn<?= $activeTab === 'products' ? ' active' : '' ?>" href="<?= htmlspecialchars($lexosTabUrl('products'), ENT_QUOTES, 'UTF-8') ?>">Produtos</a>
        <a class="lexos-tab-btn<?= $activeTab === 'sku-analysis' ? ' active' : '' ?>" href="<?= htmlspecialchars($lexosTabUrl('sku-analysis'), ENT_QUOTES, 'UTF-8') ?>">Análise de SKU</a>
    </nav>

    <div class="lexos-tab-content<?= $activeTab === 'dashboard' ? ' active' : '' ?>" data-content="dashboard">
        <div class="lexos-header">
            <h1>Dashboard de Vendas</h1>
            <form method="get" class="lexos-date-inline">
                <input type="hidden" name="page" value="<?= htmlspecialchars($dashboardPageId, ENT_QUOTES, 'UTF-8') ?>">
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
        <?php if ($channels): ?>
            <?php
                $svgPalette = ['#2563eb', '#7c3aed', '#db2777', '#ea580c', '#16a34a', '#0891b2', '#ca8a04', '#4f46e5'];
                $channelsTotalValue = array_sum($channelsChartValues);
            ?>
            <div class="lexos-chart-wrap">
                <div class="lexos-donut-center">
                    <div class="lexos-donut-visual">
                        <canvas id="lexos-channel-chart" aria-label="Faturamento por canal"></canvas>
                    </div>
                    <ul class="lexos-pie-list">
                        <?php foreach ($channels as $i => $row): ?>
                            <?php
                                $label = (string) ($row[0] ?? '');
                                $val = (float) ($row[1] ?? 0);
                                $pct = $channelsTotalValue > 0 ? ($val / $channelsTotalValue) * 100.0 : 0.0;
                                $fill = $svgPalette[$i % count($svgPalette)];
                            ?>
                            <li>
                                <span><span class="lexos-dot" style="background:<?= htmlspecialchars($fill, ENT_QUOTES, 'UTF-8') ?>"></span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= htmlspecialchars(number_format($pct, 1, ',', '.'), ENT_QUOTES, 'UTF-8') ?>%</strong>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="lexos-tab-content<?= $activeTab === 'comparison' ? ' active' : '' ?>" data-content="comparison">
        <h1>Comparação Anual</h1>
        <form method="get" style="margin-bottom:10px;">
            <input type="hidden" name="page" value="<?= htmlspecialchars($dashboardPageId, ENT_QUOTES, 'UTF-8') ?>">
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
                    Crescimento <?= htmlspecialchars((string) $year, ENT_QUOTES, 'UTF-8') ?><strong><?= ($val >= 0 ? '+' : '') . htmlspecialchars(number_format((float) $val, 1, ',', '.'), ENT_QUOTES, 'UTF-8') ?>%</strong>
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
        <div class="lexos-products-header">
            <h1>Produtos Mais Vendidos</h1>
            <?php if ($app['lexosCredentialsService']->hasHubToken()): ?>
                <?php
                    $exportQs = http_build_query([
                        'page' => $dashboardPageId,
                        'lexos_tab' => 'products',
                        'export' => 'xlsx',
                        'lexos_start' => $dStart,
                        'lexos_end' => $dEnd,
                        'lexos_search' => $search,
                    ], '', '&', PHP_QUERY_RFC3986);
                ?>
                <a class="btn-export-xlsx" href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?' . $exportQs), ENT_QUOTES, 'UTF-8') ?>">Exportar Excel</a>
            <?php endif; ?>
        </div>
        <form method="get" class="lexos-products-filters">
            <input type="hidden" name="page" value="<?= htmlspecialchars($dashboardPageId, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="lexos_tab" value="products">
            <input type="hidden" name="lexos_sku" value="<?= htmlspecialchars($sku) ?>">
            <div class="lexos-filter-item">
                <label for="lexos-product-start">Data Inicial</label>
                <input id="lexos-product-start" type="date" name="lexos_start" value="<?= htmlspecialchars($dStart) ?>">
            </div>
            <div class="lexos-filter-item">
                <label for="lexos-product-end">Data Final</label>
                <input id="lexos-product-end" type="date" name="lexos_end" value="<?= htmlspecialchars($dEnd) ?>">
            </div>
            <div class="lexos-filter-item lexos-filter-search">
                <label for="lexos-product-search">Buscar</label>
                <input id="lexos-product-search" type="text" name="lexos_search" value="<?= htmlspecialchars($search) ?>" placeholder="SKU, Nome ou EAN">
            </div>
            <div class="lexos-filter-item">
                <label for="lexos-products-take">Itens/página</label>
                <select id="lexos-products-take" name="lexos_products_take">
                    <?php foreach ([10, 20, 50, 100] as $takeOpt): ?>
                        <option value="<?= $takeOpt ?>" <?= $productsPerPage === $takeOpt ? 'selected' : '' ?>><?= $takeOpt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
                    <td title="<?= htmlspecialchars((string) ($p['Nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strlen((string) ($p['Nome'] ?? '')) > 50 ? mb_substr((string) ($p['Nome'] ?? ''), 0, 50) . '…' : (string) ($p['Nome'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($p['Ean'] ?? '')) ?></td>
                    <td><?= htmlspecialchars(number_format((float) ($p['Estoque'] ?? 0), 0, ',', '.')) ?></td>
                    <td>R$ <?= htmlspecialchars(number_format((float) ($p['TotalVendidoItem'] ?? 0), 2, ',', '.')) ?></td>
                    <td><?= htmlspecialchars(number_format((float) ($p['TotalUnidadesVendidas'] ?? 0), 0, ',', '.')) ?></td>
                    <td><?= htmlspecialchars((string) ($p['Classificacao'] ?? '-')) ?></td>
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
                <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=' . rawurlencode($dashboardPageId) . '&lexos_tab=products&lexos_start=' . urlencode($dStart) . '&lexos_end=' . urlencode($dEnd) . '&lexos_search=' . urlencode($search) . '&lexos_sku=' . urlencode($sku) . '&lexos_products_take=' . urlencode((string) $productsPerPage) . '&lexos_products_page=' . urlencode((string) $prevPage))) ?>">Anterior</a>
            <?php endif; ?>
            <span>Página <?= htmlspecialchars((string) $productsPage) ?> de <?= htmlspecialchars((string) $productsTotalPages) ?></span>
            <?php if ($productsPage < $productsTotalPages): ?>
                <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=' . rawurlencode($dashboardPageId) . '&lexos_tab=products&lexos_start=' . urlencode($dStart) . '&lexos_end=' . urlencode($dEnd) . '&lexos_search=' . urlencode($search) . '&lexos_sku=' . urlencode($sku) . '&lexos_products_take=' . urlencode((string) $productsPerPage) . '&lexos_products_page=' . urlencode((string) $nextPage))) ?>">Próxima</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="lexos-tab-content<?= $activeTab === 'sku-analysis' ? ' active' : '' ?>" data-content="sku-analysis">
        <h1>Análise de SKU</h1>
        <div class="lexos-sku-toolbar">
            <form method="get" class="lexos-sku-filters" action="">
                <input type="hidden" name="page" value="<?= htmlspecialchars($dashboardPageId, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="lexos_tab" value="sku-analysis">
                <input type="hidden" name="lexos_search" value="<?= htmlspecialchars($search) ?>">
                <div class="lexos-sku-filter-row">
                    <div class="lexos-sku-filter-item">
                        <label for="lexos-sku-input">SKU</label>
                        <input id="lexos-sku-input" type="text" name="lexos_sku" value="<?= htmlspecialchars($sku) ?>" placeholder="Digite o SKU" autocomplete="off">
                    </div>
                    <div class="lexos-sku-filter-item">
                        <label for="lexos-sku-start">Data inicial</label>
                        <input id="lexos-sku-start" type="date" name="lexos_start" value="<?= htmlspecialchars($dStart) ?>">
                    </div>
                    <div class="lexos-sku-filter-item">
                        <label for="lexos-sku-end">Data final</label>
                        <input id="lexos-sku-end" type="date" name="lexos_end" value="<?= htmlspecialchars($dEnd) ?>">
                    </div>
                    <button type="submit">Buscar</button>
                </div>
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
                $cobertura = $mediaDia > 0 ? floor($estoqueAtual / $mediaDia) : null;
                $skuNomeProduto = '';
                if ($stockRows !== [] && is_array($stockRows[0])) {
                    $skuNomeProduto = trim((string) ($stockRows[0]['Nome'] ?? $stockRows[0]['nome'] ?? $stockRows[0]['Descricao'] ?? ''));
                }
                if ($skuNomeProduto === '' && $salesRows !== [] && is_array($salesRows[0])) {
                    $skuNomeProduto = trim((string) ($salesRows[0]['Nome'] ?? $salesRows[0]['nome'] ?? $salesRows[0]['Produto'] ?? ''));
                }
            ?>
            <?php if ($skuNomeProduto !== ''): ?>
                <div class="lexos-sku-product-bar"><?= htmlspecialchars($skuNomeProduto, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="lexos-metrics" style="grid-template-columns:repeat(4,minmax(0,1fr));">
                <div class="lexos-metric">Estoque Atual<strong><?= htmlspecialchars(number_format($estoqueAtual, 0, ',', '.')) ?></strong></div>
                <div class="lexos-metric">Faturamento no Período<strong>R$ <?= htmlspecialchars(number_format($totalFat, 2, ',', '.')) ?></strong></div>
                <div class="lexos-metric">Vendas Médias / Dia<strong><?= htmlspecialchars(number_format($mediaDia, 2, ',', '.')) ?> un/dia</strong></div>
                <div class="lexos-metric">Cobertura de Estoque<strong><?php if ($cobertura === null): ?>N/A<?php else: ?><span class="lexos-metric-alert"><?= htmlspecialchars((string) $cobertura) ?> dias</span><?php endif; ?></strong></div>
            </div>
            <?php if ($skuChartLabels !== []): ?>
                <div class="lexos-chart-wrap lexos-sku-chart-block">
                    <h2>Vendas diárias</h2>
                    <div class="lexos-sku-chart-host">
                        <canvas id="lexos-sku-chart"></canvas>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="lexos-sku-hint">Informe o SKU e o período, depois clique em <strong>Buscar</strong>.</p>
        <?php endif; ?>
    </div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js" crossorigin="anonymous"></script>
<script>
(function () {
    if (typeof Chart !== 'undefined') {
        var channelLabels = <?= $lexosJsonEmbed($channelsChartLabels) ?>;
        var channelValues = <?= $lexosJsonEmbed($channelsChartValues) ?>;
        var chCanvas = document.getElementById('lexos-channel-chart');
        if (chCanvas && channelLabels.length) {
            new Chart(chCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: channelLabels,
                    datasets: [{
                        data: channelValues,
                        backgroundColor: ['#2563eb','#7c3aed','#db2777','#ea580c','#16a34a','#0891b2','#ca8a04','#4f46e5','#0d9488','#be123c'],
                        borderWidth: 1,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '58%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var total = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                                    var pct = total > 0 ? (100 * ctx.raw / total).toFixed(1).replace('.', ',') : '0';
                                    return ctx.label + ': ' + pct + '%';
                                }
                            }
                        }
                    }
                }
            });
        }

        var prodLabels = <?= $lexosJsonEmbed($productsChartLabels) ?>;
        var prodValues = <?= $lexosJsonEmbed($productsChartValues) ?>;
        var pCanvas = document.getElementById('lexos-products-chart');
        if (pCanvas && prodLabels.length) {
            new Chart(pCanvas.getContext('2d'), {
                type: 'bar',
                data: { labels: prodLabels, datasets: [{ label: 'Faturamento', data: prodValues, backgroundColor: 'rgba(37,99,235,.55)' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
        }

        var skuLabels = <?= $lexosJsonEmbed($skuChartLabels) ?>;
        var skuRev = <?= $lexosJsonEmbed($skuChartValues) ?>;
        var skuQty = <?= $lexosJsonEmbed($skuChartQtyValues) ?>;
        var sCanvas = document.getElementById('lexos-sku-chart');
        if (sCanvas && skuLabels.length) {
            new Chart(sCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: skuLabels,
                    datasets: [
                        {
                            type: 'bar',
                            label: 'Faturamento (R$)',
                            data: skuRev,
                            yAxisID: 'y',
                            backgroundColor: 'rgba(147, 197, 253, 0.9)',
                            borderRadius: 4,
                            order: 1
                        },
                        {
                            type: 'line',
                            label: 'Quantidade',
                            data: skuQty,
                            yAxisID: 'y1',
                            borderColor: '#22c55e',
                            backgroundColor: 'rgba(34, 197, 94, 0.08)',
                            fill: false,
                            tension: 0.2,
                            pointRadius: 5,
                            pointBackgroundColor: '#22c55e',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            borderWidth: 2.5,
                            order: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', labels: { usePointStyle: true, padding: 16 } },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) {
                                    var v = ctx.parsed.y;
                                    if (ctx.datasetIndex === 0) {
                                        return 'Faturamento (R$): ' + (typeof v === 'number' ? v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : v);
                                    }
                                    return 'Quantidade: ' + v + ' un';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: { display: true, text: 'Data' },
                            ticks: { maxRotation: 45, minRotation: 0 }
                        },
                        y: {
                            position: 'left',
                            title: { display: true, text: 'Faturamento (R$)' },
                            ticks: {
                                callback: function (val) {
                                    var v = Number(val);
                                    if (v >= 1e6) { return 'R$ ' + (v / 1e6).toFixed(1) + 'M'; }
                                    if (v >= 1e3) { return 'R$ ' + (v / 1e3).toFixed(0) + 'k'; }
                                    return 'R$ ' + v;
                                }
                            }
                        },
                        y1: {
                            position: 'right',
                            title: { display: true, text: 'Quantidade (un)' },
                            grid: { drawOnChartArea: false },
                            ticks: {
                                callback: function (val) { return val + ' un'; }
                            }
                        }
                    }
                }
            });
        }

        var cmpMonths = <?= $lexosJsonEmbed($comparisonMonths) ?>;
        var cmpSeries = <?= $lexosJsonEmbed($comparisonSeries) ?>;
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
<?php if ($lexosOpenHubWindow): ?>
<script>
(function () {
    var hubUrl = 'https://app-hub.lexos.com.br/';
    var popupName = 'lexosHubLogin';
    var openHub = function () {
        var win = window.open(hubUrl, popupName, 'width=1120,height=820,menubar=no,toolbar=no,location=yes,status=no,resizable=yes,scrollbars=yes');
        return win;
    };
    var btn = document.getElementById('lexos-hub-open-btn');
    if (btn) {
        btn.addEventListener('click', function () { openHub(); });
    }
    var popup = openHub();
    if (!popup || popup.closed || typeof popup.closed === 'undefined') {
        var hint = document.getElementById('lexos-hub-popup-hint');
        if (hint) {
            hint.style.display = 'block';
        }
    }
})();
</script>
<?php endif; ?>
