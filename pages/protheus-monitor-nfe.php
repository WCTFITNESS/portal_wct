<?php

declare(strict_types=1);

use App\Repositories\ProtheusSettingsRepository;
use App\Services\ProtheusNfeMonitorService;

$feedback = null;
$feedbackClass = 'ok';
$result = null;

$filial = trim((string) ($_GET['filial'] ?? '0101'));
$settings = $app['protheusSettingsRepository']->getSettings();
$dataCorte = ProtheusSettingsRepository::resolveDataCorte($settings);
$emissaoDe = trim((string) ($_GET['emissao_de'] ?? $dataCorte));
$emissaoAte = trim((string) ($_GET['emissao_ate'] ?? date('Y-m-d')));
$statusFilter = strtolower(trim((string) ($_GET['status_sefaz'] ?? '')));
if ($emissaoDe === '') {
    $emissaoDe = $dataCorte;
}
if ($emissaoDe < $dataCorte) {
    $emissaoDe = $dataCorte;
}
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = max(10, min(200, (int) ($_GET['per_page'] ?? 50)));

$monitorService = $app['protheusNfeMonitorService'];

if ($settings === null) {
    $feedback = 'Configure o Protheus em Config Protheus antes de consultar.';
    $feedbackClass = 'err';
} elseif (!$app['protheusConnectionService']->isDriverAvailable()) {
    $feedback = 'Driver SQL Server (pdo_sqlsrv ou pdo_dblib) nao disponivel neste PHP.';
    $feedbackClass = 'err';
} else {
    try {
        $result = $monitorService->listNotas($filial, $emissaoDe, $emissaoAte, $statusFilter, $page, $perPage);
    } catch (Throwable $exception) {
        $feedback = 'Erro na consulta: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

function protheus_nfe_query(array $overrides = []): string
{
    global $baseUrl, $filial, $emissaoDe, $emissaoAte, $perPage, $statusFilter;

    $params = array_merge([
        'page' => 'protheus-monitor-nfe',
        'filial' => $filial,
        'emissao_de' => $emissaoDe,
        'emissao_ate' => $emissaoAte,
        'status_sefaz' => $statusFilter,
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
?>
<section class="card protheus-monitor-card">
    <h1>Monitor de NF-e (SEFAZ)</h1>
    <p>Notas fiscais de saida (SF2) com transmissao registrada (F2_FIMP). Em branco nao entram na lista.</p>
    <p style="font-size:.9rem;color:#64748b;">
        Data de corte (config): <strong><?= htmlspecialchars(date('d/m/Y', strtotime($dataCorte))) ?></strong>
        — emissao anterior nao e listada.
        <?php if (is_array($result)): ?>
            | Log SEFAZ: <strong><?= !empty($result['has_sefaz_log']) ? htmlspecialchars((string) ($result['sefaz_log_table'] ?? 'ok')) : 'nao encontrado (usa chave NF-e)' ?></strong>
        <?php endif; ?>
    </p>

    <div class="protheus-legend">
        <span class="legend-item legend-sefaz-autorizada">S — Transmitido + chave</span>
        <span class="legend-item legend-sefaz-pendente">S — Transmitido sem chave</span>
        <span class="legend-item legend-sefaz-rejeitada">N — Negado | D — Uso denegado</span>
    </div>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="get" class="protheus-filters">
        <input type="hidden" name="page" value="protheus-monitor-nfe">
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
            <label>Status SEFAZ
                <select name="status_sefaz">
                    <option value="">Todos</option>
                    <option value="autorizada"<?= $statusFilter === 'autorizada' ? ' selected' : '' ?>>Autorizada</option>
                    <option value="pendente"<?= $statusFilter === 'pendente' ? ' selected' : '' ?>>Pendente</option>
                    <option value="rejeitada"<?= $statusFilter === 'rejeitada' ? ' selected' : '' ?>>Rejeitada</option>
                </select>
            </label>
            <label>Por pagina
                <select name="per_page">
                    <?php foreach ([25, 50, 100, 200] as $opt): ?>
                        <option value="<?= $opt ?>"<?= $perPage === $opt ? ' selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <button type="submit">Filtrar</button>
    </form>

    <?php if (is_array($result)): ?>
        <div class="protheus-summary-row">
            <p class="protheus-summary">
                Total: <strong><?= (int) $result['total'] ?></strong>
                | Pagina <strong><?= (int) $result['page'] ?></strong> de <strong><?= (int) $result['total_pages'] ?></strong>
            </p>
            <?php if ($canExport): ?>
                <a
                    class="btn-export-xlsx"
                    href="<?= htmlspecialchars(protheus_nfe_query(['export' => 'xlsx', 'p' => null])) ?>"
                >Exportar Excel</a>
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
                                    <td><?= $monitorService->displayCellHtml((string) $key, $row[$key] ?? null, $row) ?></td>
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
                    <a href="<?= htmlspecialchars(protheus_nfe_query(['p' => (string) ($page - 1)])) ?>">&laquo; Anterior</a>
                <?php endif; ?>
                <span>Pagina <?= (int) $page ?> / <?= (int) $result['total_pages'] ?></span>
                <?php if ($page < (int) $result['total_pages']): ?>
                    <a href="<?= htmlspecialchars(protheus_nfe_query(['p' => (string) ($page + 1)])) ?>">Proxima &raquo;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<style>
    .protheus-monitor-card { width: 100%; max-width: 100%; }
    .protheus-monitor-card h1 { font-size: 1.25rem; margin-bottom: 8px; }
    .protheus-monitor-card > p { margin: 0 0 8px 0; font-size: .88rem; }
    .protheus-legend { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
    .legend-item { display: inline-block; padding: 5px 9px; border-radius: 6px; font-size: .8rem; font-weight: bold; }
    .legend-sefaz-autorizada { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .legend-sefaz-pendente { background: #fef9c3; color: #713f12; border: 1px solid #fde047; }
    .legend-sefaz-rejeitada { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .protheus-filters .filter-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px 14px;
        margin-top: 6px;
        align-items: end;
    }
    .protheus-filters label { margin-top: 0; font-weight: bold; font-size: .85rem; }
    .protheus-filters input, .protheus-filters select { margin-top: 4px; }
    .protheus-filters button { margin-top: 0; }
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
        letter-spacing: .04em;
        text-transform: uppercase;
        text-decoration: none;
        white-space: nowrap;
    }
    a.btn-export-xlsx:hover { background: #f5b700; color: #111111; }
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
    .protheus-table tbody tr:hover { background: #f8fafc; }
    tr.row-sefaz-autorizada { background: #f0fdf4 !important; }
    tr.row-sefaz-pendente { background: #fefce8 !important; }
    tr.row-sefaz-rejeitada { background: #fef2f2 !important; }
    .sefaz-status-autorizada { color: #166534; font-weight: bold; }
    .sefaz-status-pendente { color: #a16207; font-weight: bold; }
    .sefaz-status-rejeitada { color: #dc2626; font-weight: bold; }
    .pagination { display: flex; align-items: center; gap: 14px; margin-top: 12px; flex-wrap: wrap; font-size: .88rem; }
    .pagination a { font-weight: bold; }
    @media (max-width: 1100px) {
        .protheus-filters .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>
