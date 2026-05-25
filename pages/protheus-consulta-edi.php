<?php

declare(strict_types=1);

use App\Repositories\ProtheusSettingsRepository;
use App\Services\ProtheusEdiConsultaService;

$feedback = null;
$feedbackClass = 'ok';
$result = null;

$filial = trim((string) ($_GET['filial'] ?? '0101'));
$settings = $app['protheusSettingsRepository']->getSettings();
$dataCorte = ProtheusSettingsRepository::resolveDataCorte($settings);
$dataDe = trim((string) ($_GET['data_de'] ?? $dataCorte));
$dataAte = trim((string) ($_GET['data_ate'] ?? date('Y-m-d')));
$notaFiscal = trim((string) ($_GET['nota_fiscal'] ?? ''));
$idlexo = trim((string) ($_GET['idlexo'] ?? ''));
$pedMar = trim((string) ($_GET['ped_mar'] ?? ''));
$codOcorrencia = trim((string) ($_GET['cod_ocorrencia'] ?? ''));
$motivoOcorrencia = trim((string) ($_GET['motivo_ocorrencia'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = max(10, min(200, (int) ($_GET['per_page'] ?? ProtheusEdiConsultaService::DEFAULT_PER_PAGE)));

if ($dataDe === '') {
    $dataDe = $dataCorte;
}
if ($dataDe < $dataCorte) {
    $dataDe = $dataCorte;
}

$monitorService = $app['protheusEdiConsultaService'];

if ($settings === null) {
    $feedback = 'Configure o Protheus em Config Protheus antes de consultar.';
    $feedbackClass = 'err';
} elseif (!$app['protheusConnectionService']->isDriverAvailable()) {
    $feedback = 'Driver SQL Server (pdo_sqlsrv ou pdo_dblib) nao disponivel neste PHP.';
    $feedbackClass = 'err';
} else {
    try {
        $result = $monitorService->listOcorrencias(
            $filial,
            $dataDe,
            $dataAte,
            $notaFiscal,
            $idlexo,
            $pedMar,
            $codOcorrencia,
            $motivoOcorrencia,
            $status,
            $page,
            $perPage
        );
    } catch (Throwable $exception) {
        $feedback = 'Erro na consulta: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

function protheus_edi_query(array $overrides = []): string
{
    global $baseUrl, $filial, $dataDe, $dataAte, $notaFiscal, $idlexo, $pedMar;
    global $codOcorrencia, $motivoOcorrencia, $status, $perPage;

    $params = array_merge([
        'page' => 'protheus-consulta-edi',
        'filial' => $filial,
        'data_de' => $dataDe,
        'data_ate' => $dataAte,
        'nota_fiscal' => $notaFiscal,
        'idlexo' => $idlexo,
        'ped_mar' => $pedMar,
        'cod_ocorrencia' => $codOcorrencia,
        'motivo_ocorrencia' => $motivoOcorrencia,
        'status' => $status,
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
$deletedHint = ProtheusEdiConsultaService::deletedFlagSql('GWD');
?>
<section class="card protheus-monitor-card">
    <h1>Monitor EDI</h1>
    <p>
        Lista <strong>ocorrencias EDI de transporte</strong> (<strong>GWL</strong> / <strong>GWD</strong>), nao o pedido sozinho.
        Cada linha exige NF (<strong>SF2</strong>), pedido com <strong>ID Lexos</strong> (<strong>SC5</strong> / <strong>ZA4</strong>) e data da ocorrencia no periodo informado.
    </p>
    <p style="font-size:.9rem;color:#64748b;">
        Periodo do filtro: <strong>data da ocorrencia</strong> (<code>GWD_DTOCOR</code>), nao data do pedido.
        Ped. marketplace busca em <strong>ZA4.ZA4_PEDMAR</strong> e <strong>SC5.C5_PEDMAR</strong>.
        Transportadoras excluidas: 000006, 000176, 000177, 000179, 000265.
        Exclusao logica: <code><?= htmlspecialchars($deletedHint) ?></code>.
    </p>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="get" class="protheus-filters">
        <input type="hidden" name="page" value="protheus-consulta-edi">
        <div class="filter-grid">
            <label>Filial
                <input type="text" name="filial" value="<?= htmlspecialchars($filial) ?>" maxlength="4" required>
            </label>
            <label>Ocorrencia de
                <input type="date" name="data_de" value="<?= htmlspecialchars($dataDe) ?>" min="<?= htmlspecialchars($dataCorte) ?>">
            </label>
            <label>Ocorrencia ate
                <input type="date" name="data_ate" value="<?= htmlspecialchars($dataAte) ?>">
            </label>
            <label>Nota fiscal
                <input type="text" name="nota_fiscal" value="<?= htmlspecialchars($notaFiscal) ?>" placeholder="Ex.: 000546036">
            </label>
            <label>ID Lexos (contem)
                <input type="text" name="idlexo" value="<?= htmlspecialchars($idlexo) ?>" placeholder="Ex.: 12345">
            </label>
            <label>Ped. marketplace (ZA4 ou SC5)
                <input type="text" name="ped_mar" value="<?= htmlspecialchars($pedMar) ?>" placeholder="Ex.: 2000016375887148">
            </label>
            <label>Cod. ocorrencia
                <input type="text" name="cod_ocorrencia" value="<?= htmlspecialchars($codOcorrencia) ?>" placeholder="Ex.: 001" maxlength="10">
            </label>
            <label>Motivo ocorrencia
                <input type="text" name="motivo_ocorrencia" value="<?= htmlspecialchars($motivoOcorrencia) ?>" placeholder="Ex.: 001" maxlength="10">
            </label>
            <label>Status integracao
                <input type="text" name="status" value="<?= htmlspecialchars($status) ?>" placeholder="Em branco = todos" maxlength="10">
            </label>
            <label>Por pagina
                <select name="per_page">
                    <?php foreach ([50, 100, 150, 200] as $opt): ?>
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
                <a class="btn-export-xlsx" href="<?= htmlspecialchars(protheus_edi_query(['export' => 'xlsx', 'p' => null])) ?>">Exportar Excel</a>
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

        <?php if ((int) $result['total_pages'] > 1): ?>
            <nav class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= htmlspecialchars(protheus_edi_query(['p' => (string) ($page - 1)])) ?>">&laquo; Anterior</a>
                <?php endif; ?>
                <span>Pagina <?= (int) $page ?> / <?= (int) $result['total_pages'] ?></span>
                <?php if ($page < (int) $result['total_pages']): ?>
                    <a href="<?= htmlspecialchars(protheus_edi_query(['p' => (string) ($page + 1)])) ?>">Proxima &raquo;</a>
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
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px 14px;
        margin-top: 6px;
        align-items: end;
    }
    .protheus-filters label { margin-top: 0; font-weight: bold; font-size: .85rem; }
    .protheus-filters input,
    .protheus-filters select {
        margin-top: 4px;
        width: 100%;
        box-sizing: border-box;
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
    .protheus-table th,
    .protheus-table td {
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
    tr.row-edi-alerta { background: #ffedd5 !important; }
    tr.row-edi-erro { background: #fee2e2 !important; }
    .cell-desc { max-width: 280px; display: inline-block; }
    .pagination { display: flex; gap: 14px; margin-top: 12px; font-size: .88rem; }
    @media (max-width: 1100px) {
        .protheus-filters .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>
