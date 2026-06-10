<?php

declare(strict_types=1);

use App\Repositories\ProtheusSettingsRepository;

$feedback = null;
$feedbackClass = 'ok';
$result = null;

$filial = trim((string) ($_GET['filial'] ?? '0101'));
$settings = $app['protheusSettingsRepository']->getSettings();
$dataCorte = ProtheusSettingsRepository::resolveDataCorte($settings);
$emissaoDe = trim((string) ($_GET['emissao_de'] ?? $dataCorte));
$emissaoAte = trim((string) ($_GET['emissao_ate'] ?? date('Y-m-d')));
if ($emissaoDe === '') {
    $emissaoDe = $dataCorte;
}
if ($emissaoDe < $dataCorte) {
    $emissaoDe = $dataCorte;
}
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = max(10, min(200, (int) ($_GET['per_page'] ?? 50)));
$marketplace = trim((string) ($_GET['marketplace'] ?? ''));
$filterDoc = trim((string) ($_GET['doc'] ?? ''));
$filterPedMarketplace = trim((string) ($_GET['ped_marketplace'] ?? ''));

$monitorService = $app['protheusRomaneioMonitorService'];
$parsedDocs = $monitorService->parseBatchFilter($filterDoc);
$parsedPedidos = $monitorService->parseBatchFilter($filterPedMarketplace);
$marketplaceOptions = [];

if ($settings === null) {
    $feedback = 'Configure o Protheus em Config Protheus antes de consultar.';
    $feedbackClass = 'err';
} elseif (!$app['protheusConnectionService']->isDriverAvailable()) {
    $feedback = 'Driver SQL Server (pdo_sqlsrv ou pdo_dblib) nao disponivel neste PHP.';
    $feedbackClass = 'err';
} else {
    try {
        $marketplaceOptions = $monitorService->listMarketplaces($filial, $emissaoDe, $emissaoAte);
        if ($marketplace !== '' && !in_array($marketplace, $marketplaceOptions, true)) {
            $marketplace = '';
        }
        $result = $monitorService->listRomaneios(
            $filial,
            $emissaoDe,
            $emissaoAte,
            $page,
            $perPage,
            $marketplace,
            $filterDoc,
            $filterPedMarketplace
        );
    } catch (Throwable $exception) {
        $feedback = 'Erro na consulta: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

function protheus_monitor_query(array $overrides = []): string
{
    global $baseUrl, $filial, $emissaoDe, $emissaoAte, $perPage, $marketplace;

    $params = array_merge([
        'page' => 'protheus-monitor-romaneio',
        'filial' => $filial,
        'emissao_de' => $emissaoDe,
        'emissao_ate' => $emissaoAte,
        'per_page' => (string) $perPage,
        'marketplace' => $marketplace,
    ], $overrides);

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }

    return portal_wct_public_path($baseUrl, 'index.php?' . http_build_query($params));
}

$columns = $monitorService::exportColumns();
$canExport = $settings !== null && $app['protheusConnectionService']->isDriverAvailable();
?>
<section class="card protheus-monitor-card">
    <h1>Monitor de Romaneio</h1>
    <p>Notas fiscais sem liberacao de romaneio (GW1_DTSAI / GW1_HRSAI vazios) com ID Lexos preenchido.</p>
    <p style="font-size:.9rem;color:#64748b;">
        Data de corte (config): <strong><?= htmlspecialchars(date('d/m/Y', strtotime($dataCorte))) ?></strong>
        — emissao anterior nao e listada.
    </p>

    <div class="protheus-legend">
        <span class="legend-item legend-romaneio">Sem romaneio (GW1_NRROM)</span>
        <span class="legend-item legend-liberacao">Sem data liberacao (GW1_DTSAI)</span>
    </div>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="get" class="protheus-filters">
        <input type="hidden" name="page" value="protheus-monitor-romaneio">
        <div class="filter-grid">
            <label>Filial
                <input type="text" name="filial" value="<?= htmlspecialchars($filial) ?>" maxlength="4" required>
            </label>
            <label>Emissao de
                <input type="date" name="emissao_de" value="<?= htmlspecialchars($emissaoDe) ?>" min="<?= htmlspecialchars($dataCorte) ?>" required>
            </label>
            <label>Emissao ate
                <input type="date" name="emissao_ate" value="<?= htmlspecialchars($emissaoAte) ?>" required>
            </label>
            <label>Marketplace
                <select name="marketplace">
                    <option value="">Todos</option>
                    <?php foreach ($marketplaceOptions as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"<?= $marketplace === $opt ? ' selected' : '' ?>>
                            <?= htmlspecialchars($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-span-2">Nº nota (Doc)
                <input type="text" name="doc" value="<?= htmlspecialchars($filterDoc) ?>"
                       placeholder="Ex.: 000123456, 000123457 (virgula)">
            </label>
            <label class="filter-span-2">Ped. marketplace
                <input type="text" name="ped_marketplace" value="<?= htmlspecialchars($filterPedMarketplace) ?>"
                       placeholder="Ex.: 2000016479266634, 2000016479266635">
            </label>
            <label>Por pagina
                <select name="per_page">
                    <?php foreach ([25, 50, 100, 200] as $opt): ?>
                        <option value="<?= $opt ?>"<?= $perPage === $opt ? ' selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <p class="filter-hint">Doc e Ped. marketplace aceitam consulta em lote: separe os valores por virgula (ate 100 por campo).</p>
        <button type="submit">Filtrar</button>
    </form>

    <?php if (is_array($result)): ?>
        <?php
        $rowsOnPage = count($result['rows']);
        ?>
        <div class="protheus-summary-row">
            <p class="protheus-summary">
                Total: <strong><?= (int) $result['total'] ?></strong>
                | Nesta pagina: <strong><?= (int) $rowsOnPage ?></strong> linha(s)
                | Pagina <strong><?= (int) $result['page'] ?></strong> de <strong><?= (int) $result['total_pages'] ?></strong>
                <?php if ($marketplace !== ''): ?>
                    | Marketplace: <strong><?= htmlspecialchars($marketplace) ?></strong>
                <?php endif; ?>
                <?php if ($parsedDocs !== []): ?>
                    | Doc: <strong><?= count($parsedDocs) ?></strong> nota(s)
                <?php endif; ?>
                <?php if ($parsedPedidos !== []): ?>
                    | Ped. marketplace: <strong><?= count($parsedPedidos) ?></strong> pedido(s)
                <?php endif; ?>
            </p>
            <?php if ($canExport): ?>
                <a
                    class="btn-export-xlsx"
                    href="<?= htmlspecialchars(protheus_monitor_query(['export' => 'xlsx', 'p' => null])) ?>"
                >Exportar Excel</a>
            <?php endif; ?>
        </div>

        <?php
        $missingDocs = is_array($result['missing_docs'] ?? null) ? $result['missing_docs'] : [];
        $missingPedidos = is_array($result['missing_pedidos'] ?? null) ? $result['missing_pedidos'] : [];
        ?>
        <?php if ($missingDocs !== [] || $missingPedidos !== []): ?>
            <div class="batch-missing-alert">
                <strong>Nao encontrados com os filtros atuais:</strong>
                <?php if ($missingDocs !== []): ?>
                    <p>
                        <span class="batch-missing-label">Notas (<?= count($missingDocs) ?>):</span>
                        <?= htmlspecialchars(implode(', ', $missingDocs)) ?>
                    </p>
                <?php endif; ?>
                <?php if ($missingPedidos !== []): ?>
                    <p>
                        <span class="batch-missing-label">Pedidos marketplace (<?= count($missingPedidos) ?>):</span>
                        <?= htmlspecialchars(implode(', ', $missingPedidos)) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="protheus-table">
                <thead>
                    <tr>
                        <th class="col-row-num" title="Linha nesta pagina">#</th>
                        <?php foreach ($columns as $label): ?>
                            <th><?= htmlspecialchars($label) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result['rows'] === []): ?>
                        <tr><td colspan="<?= count($columns) + 1 ?>">Nenhum registro nesta pagina.</td></tr>
                    <?php else: ?>
                        <?php foreach ($result['rows'] as $rowIndex => $row): ?>
                            <?php
                            $row = is_array($row) ? $row : [];
                            $alertClass = $monitorService->rowAlertClass($row);
                            ?>
                            <tr class="<?= htmlspecialchars($alertClass) ?>">
                                <td class="col-row-num"><?= (int) ($rowIndex + 1) ?></td>
                                <?php foreach (array_keys($columns) as $key): ?>
                                    <td><?= $monitorService->displayCellHtml((string) $key, $row[$key] ?? null) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ((int) $result['total_pages'] > 1): ?>
            <nav class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars(protheus_monitor_query(['p' => (string) ($page - 1)])) ?>">&laquo; Anterior</a>
                <?php endif; ?>
                <span>Pagina <?= (int) $page ?> / <?= (int) $result['total_pages'] ?></span>
                <?php if ($page < (int) $result['total_pages']): ?>
                    <a href="<?= htmlspecialchars(protheus_monitor_query(['p' => (string) ($page + 1)])) ?>">Proxima &raquo;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<style>
    .protheus-monitor-card {
        width: 100%;
        max-width: 100%;
    }
    .protheus-monitor-card h1 { font-size: 1.25rem; margin-bottom: 8px; }
    .protheus-monitor-card > p { margin: 0 0 8px 0; font-size: .88rem; }
    .protheus-legend { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
    .legend-item { display: inline-block; padding: 5px 9px; border-radius: 6px; font-size: .8rem; font-weight: bold; }
    .legend-romaneio { background: #fef9c3; color: #713f12; border: 1px solid #fde047; }
    .legend-liberacao { background: #ffedd5; color: #9a3412; border: 1px solid #fdba74; }
    .protheus-filters .filter-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px 14px;
        margin-top: 6px;
        align-items: end;
    }
    .protheus-filters label { margin-top: 0; font-weight: bold; font-size: .85rem; }
    .protheus-filters label.filter-span-2 { grid-column: span 2; }
    .protheus-filters input, .protheus-filters select { margin-top: 4px; width: 100%; }
    .protheus-filters button { margin-top: 0; }
    .protheus-filters .filter-hint {
        margin: 8px 0 0;
        font-size: .8rem;
        color: #64748b;
        grid-column: 1 / -1;
    }
    .protheus-summary-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 12px 0 6px;
    }
    .protheus-summary { margin: 0; color: var(--wct-muted); font-size: .88rem; }
    .batch-missing-alert {
        margin: 10px 0 0;
        padding: 10px 12px;
        border-radius: 6px;
        border: 1px solid #fca5a5;
        background: #fef2f2;
        color: #991b1b;
        font-size: .85rem;
    }
    .batch-missing-alert p {
        margin: 6px 0 0;
        word-break: break-word;
    }
    .batch-missing-alert p:first-of-type {
        margin-top: 8px;
    }
    .batch-missing-label {
        font-weight: bold;
    }
    a.btn-export-xlsx {
        display: inline-block;
        padding: 9px 14px;
        border-radius: 6px;
        border: 1px solid #f5b700;
        background: #111111;
        color: #f5b700;
        font-weight: bold;
        font-size: .78rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        text-decoration: none;
        white-space: nowrap;
    }
    a.btn-export-xlsx:hover {
        background: #f5b700;
        color: #111111;
    }
    .table-wrap {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        border: 1px solid var(--wct-border);
        border-radius: 6px;
        margin-top: 6px;
    }
    .protheus-table {
        width: max-content;
        min-width: 100%;
        border-collapse: collapse;
        font-size: .78rem;
        line-height: 1.35;
    }
    .protheus-table th,
    .protheus-table td {
        padding: 5px 8px;
        border-bottom: 1px solid #e8edf5;
        text-align: left;
        vertical-align: top;
    }
    .protheus-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f1f5f9;
        white-space: nowrap;
        font-size: .75rem;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    .protheus-table .col-row-num {
        width: 2.5rem;
        min-width: 2.5rem;
        max-width: 2.5rem;
        text-align: center;
        color: #64748b;
        font-weight: bold;
        font-variant-numeric: tabular-nums;
        background: #f8fafc;
    }
    .protheus-table thead th.col-row-num {
        z-index: 3;
    }
    .protheus-table tbody tr:hover { background: #f8fafc; }
    tr.row-alert-romaneio { background: #fef9c3 !important; }
    tr.row-alert-liberacao { background: #ffedd5 !important; }
    .cell-empty-alert {
        color: #dc2626;
        font-weight: bold;
        white-space: nowrap;
    }
    .pagination { display: flex; align-items: center; gap: 14px; margin-top: 12px; flex-wrap: wrap; font-size: .88rem; }
    .pagination a { font-weight: bold; }
    @media (max-width: 1100px) {
        .protheus-filters .filter-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
</style>
