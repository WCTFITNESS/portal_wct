<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$result = null;

$filial = trim((string) ($_GET['filial'] ?? '0101'));
$emissaoDe = trim((string) ($_GET['emissao_de'] ?? '2026-01-01'));
$emissaoAte = trim((string) ($_GET['emissao_ate'] ?? '2026-05-14'));
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = max(10, min(200, (int) ($_GET['per_page'] ?? 50)));

$settings = $app['protheusSettingsRepository']->getSettings();
$monitorService = $app['protheusMedidosMonitorService'];

if ($settings === null) {
    $feedback = 'Configure o Protheus em Config Protheus antes de consultar.';
    $feedbackClass = 'err';
} elseif (!$app['protheusConnectionService']->isDriverAvailable()) {
    $feedback = 'Driver SQL Server (pdo_sqlsrv ou pdo_dblib) nao disponivel neste PHP.';
    $feedbackClass = 'err';
} else {
    try {
        $result = $monitorService->listMedidos($filial, $emissaoDe, $emissaoAte, $page, $perPage);
    } catch (Throwable $exception) {
        $feedback = 'Erro na consulta: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

function protheus_monitor_query(array $overrides = []): string
{
    global $baseUrl, $filial, $emissaoDe, $emissaoAte, $perPage;

    $params = array_merge([
        'page' => 'protheus-monitor-medidos',
        'filial' => $filial,
        'emissao_de' => $emissaoDe,
        'emissao_ate' => $emissaoAte,
        'per_page' => (string) $perPage,
    ], $overrides);

    return portal_wct_public_path($baseUrl, 'index.php?' . http_build_query($params));
}

$columns = [
    'F2_FILIAL' => 'Filial',
    'F2_DOC' => 'Doc',
    'F2_SERIE' => 'Serie',
    'F2_CLIENTE' => 'Cliente',
    'F2_LOJA' => 'Loja',
    'F2_EMISSAO' => 'Emissao',
    'F2_VALBRUT' => 'Valor bruto',
    'ROMANEIO' => 'Romaneio',
    'DT_LIBERACAO' => 'Dt liberacao',
    'HR_LIBERACAO' => 'Hr liberacao',
    'PED_Marketplace' => 'Ped. marketplace',
    'Marketplace' => 'Marketplace',
    'IDLEXOS' => 'ID Lexos',
    'GW1_SITINT' => 'Sit. int.',
];
?>
<section class="card">
    <h1>Monitor de Medidos</h1>
    <p>Notas fiscais sem liberacao de romaneio (GW1_DTSAI / GW1_HRSAI vazios) com ID Lexos preenchido.</p>

    <div class="protheus-legend">
        <span class="legend-item legend-romaneio">Sem romaneio (GW1_NRROM)</span>
        <span class="legend-item legend-liberacao">Sem data liberacao (GW1_DTSAI)</span>
    </div>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="get" class="protheus-filters">
        <input type="hidden" name="page" value="protheus-monitor-medidos">
        <div class="filter-grid">
            <label>Filial
                <input type="text" name="filial" value="<?= htmlspecialchars($filial) ?>" maxlength="4" required>
            </label>
            <label>Emissao de
                <input type="date" name="emissao_de" value="<?= htmlspecialchars($emissaoDe) ?>" required>
            </label>
            <label>Emissao ate
                <input type="date" name="emissao_ate" value="<?= htmlspecialchars($emissaoAte) ?>" required>
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
        <p class="protheus-summary">
            Total: <strong><?= (int) $result['total'] ?></strong>
            | Pagina <strong><?= (int) $result['page'] ?></strong> de <strong><?= (int) $result['total_pages'] ?></strong>
        </p>

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
                                    <td><?= htmlspecialchars((string) ($row[$key] ?? '')) ?></td>
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
    .protheus-legend { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
    .legend-item { display: inline-block; padding: 6px 10px; border-radius: 6px; font-size: .85rem; font-weight: bold; }
    .legend-romaneio { background: #fef9c3; color: #713f12; border: 1px solid #fde047; }
    .legend-liberacao { background: #ffedd5; color: #9a3412; border: 1px solid #fdba74; }
    .protheus-filters .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: 12px;
        margin-top: 8px;
    }
    .protheus-filters label { margin-top: 0; font-weight: bold; }
    .protheus-filters input, .protheus-filters select { margin-top: 6px; }
    .protheus-summary { margin: 16px 0 8px; color: var(--wct-muted); }
    .table-wrap { overflow-x: auto; }
    .protheus-table th { white-space: nowrap; }
    tr.row-alert-romaneio { background: #fef9c3 !important; }
    tr.row-alert-liberacao { background: #ffedd5 !important; }
    .pagination { display: flex; align-items: center; gap: 14px; margin-top: 16px; flex-wrap: wrap; }
    .pagination a { font-weight: bold; }
</style>
