<?php

declare(strict_types=1);

use App\Repositories\ProtheusSettingsRepository;
use App\Services\ProtheusZa4PedidosErroMonitorService;

$feedback = null;
$feedbackClass = 'ok';
$result = null;
$schemaNotes = [];

$filial = trim((string) ($_GET['filial'] ?? '0101'));
$settings = $app['protheusSettingsRepository']->getSettings();
$dataCorte = ProtheusSettingsRepository::resolveDataCorte($settings);
$dataDe = trim((string) ($_GET['data_de'] ?? $dataCorte));
$dataAte = trim((string) ($_GET['data_ate'] ?? date('Y-m-d')));
$somenteErro = ($_GET['somente_erro'] ?? '1') !== '0';
$idlexo = trim((string) ($_GET['idlexo'] ?? ''));
$pedMar = trim((string) ($_GET['ped_mar'] ?? ''));
$marketplace = trim((string) ($_GET['marketplace'] ?? ''));
$textoErro = trim((string) ($_GET['texto_erro'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = max(10, min(200, (int) ($_GET['per_page'] ?? 50)));

$parsedPedidos = [];
$marketplaceOptions = [];

if ($dataDe === '') {
    $dataDe = $dataCorte;
}
if ($dataDe < $dataCorte) {
    $dataDe = $dataCorte;
}

$monitorService = $app['protheusZa4PedidosErroMonitorService'];

if ($settings === null) {
    $feedback = 'Configure o Protheus em Config Protheus antes de consultar.';
    $feedbackClass = 'err';
} elseif (!$app['protheusConnectionService']->isDriverAvailable()) {
    $feedback = 'Driver SQL Server (pdo_sqlsrv ou pdo_dblib) nao disponivel neste PHP.';
    $feedbackClass = 'err';
} else {
    try {
        $parsedPedidos = $monitorService->parseBatchFilter($pedMar);
        $marketplaceOptions = $monitorService->defaultMarketplaceOptions();
        $result = $monitorService->listPedidos(
            $filial,
            $dataDe,
            $dataAte,
            $somenteErro,
            $idlexo,
            $pedMar,
            $textoErro,
            $page,
            $perPage,
            $marketplace
        );
        $schemaNotes = $result['schema_notes'] ?? [];
    } catch (Throwable $exception) {
        $feedback = 'Erro na consulta: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

function protheus_za4_query(array $overrides = []): string
{
    global $baseUrl, $filial, $dataDe, $dataAte, $somenteErro, $idlexo, $pedMar, $marketplace, $textoErro, $perPage;

    $params = array_merge([
        'page' => 'protheus-monitor-pedidos-erro',
        'filial' => $filial,
        'data_de' => $dataDe,
        'data_ate' => $dataAte,
        'somente_erro' => $somenteErro ? '1' : '0',
        'idlexo' => $idlexo,
        'ped_mar' => $pedMar,
        'marketplace' => $marketplace,
        'texto_erro' => $textoErro,
        'per_page' => (string) $perPage,
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
$deletedHint = ProtheusZa4PedidosErroMonitorService::deletedFlagSql('ZA4');
?>
<section class="card protheus-monitor-card">
    <h1>Monitor — Pedidos com erro (ZA4)</h1>
    <p>
        Consulta <strong>ZA4010</strong> (pedido e erro de integracao Lexos).
        <strong>SC5</strong> para ID Lexos e pedido marketplace; <strong>ZA5</strong> apenas para dados do cliente (opcional).
        Por padrao lista apenas registros com indicacao de erro em ZA4.
    </p>
    <p style="font-size:.9rem;color:#64748b;">
        Exclusao logica: <code><?= htmlspecialchars($deletedHint) ?></code> (um espaco — padrao Protheus no portal).
        Consulta paginada: no maximo <strong>200</strong> linhas por pagina; contagem e lista filtram so em ZA4
        (joins SC5/ZA5 apenas nas linhas da pagina). Timeout SQL: <strong>45s</strong>.
        Para consultas rapidas, informe <strong>pedido marketplace</strong>, <strong>ID Lexos</strong> ou <strong>marketplace</strong>.
    </p>

    <?php if ($schemaNotes !== []): ?>
        <ul style="font-size:.85rem;color:#64748b;margin:0 0 10px 1.2rem;">
            <?php foreach ($schemaNotes as $note): ?>
                <li><?= htmlspecialchars((string) $note) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="get" class="protheus-filters">
        <input type="hidden" name="page" value="protheus-monitor-pedidos-erro">
        <div class="filter-grid">
            <label>Filial
                <input type="text" name="filial" value="<?= htmlspecialchars($filial) ?>" maxlength="4" required>
            </label>
            <label>Data de
                <input type="date" name="data_de" value="<?= htmlspecialchars($dataDe) ?>" min="<?= htmlspecialchars($dataCorte) ?>">
            </label>
            <label>Data ate
                <input type="date" name="data_ate" value="<?= htmlspecialchars($dataAte) ?>">
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
            <label>ID Lexos (contem)
                <input type="text" name="idlexo" value="<?= htmlspecialchars($idlexo) ?>" placeholder="Ex.: 12345">
            </label>
            <label class="filter-span-2">Nº pedido marketplace
                <input type="text" name="ped_mar" value="<?= htmlspecialchars($pedMar) ?>"
                       placeholder="Ex.: A001335992393, W0011635941348106 (virgula)">
            </label>
            <label>Texto do erro (contem)
                <input type="text" name="texto_erro" value="<?= htmlspecialchars($textoErro) ?>" placeholder="Trecho da mensagem">
            </label>
            <label>Por pagina
                <select name="per_page">
                    <?php foreach ([25, 50, 100, 200] as $opt): ?>
                        <option value="<?= $opt ?>"<?= $perPage === $opt ? ' selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-check">
                <input type="hidden" name="somente_erro" value="0">
                <input type="checkbox" name="somente_erro" value="1"<?= $somenteErro ? ' checked' : '' ?>>
                Somente com erro
            </label>
        </div>
        <button type="submit">Filtrar</button>
    </form>

    <?php if (is_array($result)): ?>
        <div class="protheus-summary-row">
            <p class="protheus-summary">
                Total: <strong><?= (int) ($result['total'] ?? 0) < 0 ? 'nao calculado (consulta ampla)' : (string) (int) $result['total'] ?></strong>
                | Pagina <strong><?= (int) $result['page'] ?></strong> de <strong><?= (int) $result['total_pages'] ?></strong>
                <?= $somenteErro ? '| Filtro: <strong>somente erro</strong>' : '| Filtro: <strong>todos</strong>' ?>
                <?php if ($marketplace !== ''): ?>
                    | Marketplace: <strong><?= htmlspecialchars($marketplace) ?></strong>
                <?php endif; ?>
                <?php if ($parsedPedidos !== []): ?>
                    | Ped. marketplace: <strong><?= count($parsedPedidos) ?></strong> pedido(s)
                <?php endif; ?>
            </p>
            <?php if ($canExport): ?>
                <a class="btn-export-xlsx" href="<?= htmlspecialchars(protheus_za4_query(['export' => 'xlsx', 'p' => null])) ?>">Exportar Excel</a>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table class="protheus-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $label): ?>
                            <th><?= htmlspecialchars($label) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result['rows'] === []): ?>
                        <tr><td colspan="<?= count($columns) ?>">Nenhum registro nesta pagina.</td></tr>
                    <?php else: ?>
                        <?php foreach ($result['rows'] as $row): ?>
                            <?php
                            $row = is_array($row) ? $row : [];
                            $alertClass = $monitorService->rowAlertClass($row);
                            ?>
                            <tr class="<?= htmlspecialchars($alertClass) ?>">
                                <?php foreach (array_keys($columns) as $key): ?>
                                    <td><?= $monitorService->displayCellHtml((string) $key, $row[$key] ?? null) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (($result['query_hint'] ?? '') === 'broad_pagination'): ?>
            <p class="msg err" style="margin-top:10px;">
                Paginacao indisponivel em consulta ampla. Informe pedido marketplace, ID Lexos ou marketplace e filtre novamente.
            </p>
        <?php endif; ?>

        <?php if ((int) $result['total_pages'] > 1 && (int) ($result['total'] ?? 0) >= 0): ?>
            <nav class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars(protheus_za4_query(['p' => (string) ($page - 1)])) ?>">&laquo; Anterior</a>
                <?php endif; ?>
                <span>Pagina <?= (int) $page ?> / <?= (int) $result['total_pages'] ?></span>
                <?php if ($page < (int) $result['total_pages']): ?>
                    <a href="<?= htmlspecialchars(protheus_za4_query(['p' => (string) ($page + 1)])) ?>">Proxima &raquo;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<style>
    .protheus-monitor-card { width: 100%; max-width: 100%; }
    .protheus-monitor-card h1 { font-size: 1.25rem; margin-bottom: 8px; }
    .protheus-filters .filter-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px 14px;
        margin-top: 6px;
        align-items: end;
    }
    .protheus-filters label { margin-top: 0; font-weight: bold; font-size: .85rem; }
    .protheus-filters .filter-span-2 { grid-column: span 2; }
    .filter-check { display: flex; align-items: center; gap: 8px; font-weight: normal; min-height: 38px; }
    .filter-check input { width: auto; margin: 0; }
    .protheus-summary-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 12px 0 6px;
    }
    .protheus-summary { margin: 0; color: var(--wct-muted); font-size: .88rem; }
    a.btn-export-xlsx {
        display: inline-block;
        padding: 9px 14px;
        border-radius: 6px;
        border: 1px solid #f5b700;
        background: #111111;
        color: #f5b700;
        font-weight: bold;
        font-size: .78rem;
        text-decoration: none;
    }
    a.btn-export-xlsx:hover { background: #f5b700; color: #111111; }
    .table-wrap {
        width: 100%;
        overflow-x: auto;
        border: 1px solid var(--wct-border);
        border-radius: 6px;
        margin-top: 6px;
    }
    .protheus-table {
        width: max-content;
        min-width: 100%;
        border-collapse: collapse;
        font-size: .78rem;
    }
    .protheus-table th, .protheus-table td {
        padding: 5px 8px;
        border-bottom: 1px solid #e8edf5;
        vertical-align: top;
        text-align: left;
    }
    .protheus-table thead th {
        position: sticky;
        top: 0;
        background: #f1f5f9;
        white-space: nowrap;
        font-size: .75rem;
        text-transform: uppercase;
    }
    tr.row-za4-erro { background: #fee2e2 !important; }
    .cell-erro-text { color: #991b1b; font-weight: 600; }
    .pagination { display: flex; gap: 14px; margin-top: 12px; font-size: .88rem; }
    @media (max-width: 1100px) {
        .protheus-filters .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>
