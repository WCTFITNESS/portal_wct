<?php

declare(strict_types=1);

use App\Repositories\ProtheusSettingsRepository;
use App\Services\ProtheusEdiConsultaService;

$feedback = null;
$feedbackClass = 'ok';
$result = null;
$transportadoras = [];

$filial = trim((string) ($_GET['filial'] ?? '0101'));
$settings = $app['protheusSettingsRepository']->getSettings();
$dataCorte = ProtheusSettingsRepository::resolveDataCorte($settings);
$dataDe = trim((string) ($_GET['data_de'] ?? $dataCorte));
$dataAte = trim((string) ($_GET['data_ate'] ?? date('Y-m-d')));
$transportadora = trim((string) ($_GET['transportadora'] ?? ''));
$situacaoEdi = trim((string) ($_GET['sit_edi'] ?? ''));
$arquivo = trim((string) ($_GET['arquivo'] ?? ''));
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = max(10, min(200, (int) ($_GET['per_page'] ?? 50)));

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
        $transportadoras = $monitorService->listTransportadoras($filial, $dataDe, $dataAte);
        $result = $monitorService->listEdi(
            $filial,
            $dataDe,
            $dataAte,
            $transportadora,
            $situacaoEdi,
            $arquivo,
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
    global $baseUrl, $filial, $dataDe, $dataAte, $transportadora, $situacaoEdi, $arquivo, $perPage;

    $params = array_merge([
        'page' => 'protheus-consulta-edi',
        'filial' => $filial,
        'data_de' => $dataDe,
        'data_ate' => $dataAte,
        'transportadora' => $transportadora,
        'sit_edi' => $situacaoEdi,
        'arquivo' => $arquivo,
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
$sitLabels = [
    '' => 'Todas',
    '1' => '1-Importado',
    '2' => '2-Importado com erro',
    '3' => '3-Rejeitado',
    '4' => '4-Processado',
    '5' => '5-Erro impeditivo',
];
?>
<section class="card protheus-monitor-card">
    <h1>Consultas EDI — Transportadoras</h1>
    <p>
        Documentos de frete importados via EDI no SIGAGFE (tabela <strong>GXG</strong>),
        com emitente/transportadora (<strong>GU3</strong>) e chave NF-e do documento de carga (<strong>GXH</strong>, quando existir).
    </p>
    <p style="font-size:.9rem;color:#64748b;">
        Data de corte (config): <strong><?= htmlspecialchars(date('d/m/Y', strtotime($dataCorte))) ?></strong>
        — importacoes anteriores nao sao listadas por padrao.
    </p>

    <div class="protheus-legend">
        <span class="legend-item legend-edi-ok">Importado / Processado</span>
        <span class="legend-item legend-edi-rejeitado">Rejeitado</span>
        <span class="legend-item legend-edi-erro">Erro na importacao</span>
    </div>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="get" class="protheus-filters">
        <input type="hidden" name="page" value="protheus-consulta-edi">
        <div class="filter-grid">
            <label>Filial
                <input type="text" name="filial" value="<?= htmlspecialchars($filial) ?>" maxlength="4" required>
            </label>
            <label>Importacao de
                <input type="date" name="data_de" value="<?= htmlspecialchars($dataDe) ?>" min="<?= htmlspecialchars($dataCorte) ?>" required>
            </label>
            <label>Importacao ate
                <input type="date" name="data_ate" value="<?= htmlspecialchars($dataAte) ?>" required>
            </label>
            <label>Transportadora
                <select name="transportadora">
                    <option value="">Todas</option>
                    <?php foreach ($transportadoras as $tr): ?>
                        <option value="<?= htmlspecialchars($tr['cod']) ?>"<?= $transportadora === $tr['cod'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars($tr['nome'] . ' (' . $tr['cod'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Situacao EDI
                <select name="sit_edi">
                    <?php foreach ($sitLabels as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val) ?>"<?= $situacaoEdi === $val ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Arquivo EDI (contem)
                <input type="text" name="arquivo" value="<?= htmlspecialchars($arquivo) ?>" placeholder="Ex.: OCOREN, NOTFIS">
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
                    href="<?= htmlspecialchars(protheus_edi_query(['export' => 'xlsx', 'p' => null])) ?>"
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
    .protheus-monitor-card > p { margin: 0 0 8px 0; font-size: .88rem; }
    .protheus-legend { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
    .legend-item { display: inline-block; padding: 5px 9px; border-radius: 6px; font-size: .8rem; font-weight: bold; }
    .legend-edi-ok { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .legend-edi-rejeitado { background: #ffedd5; color: #9a3412; border: 1px solid #fdba74; }
    .legend-edi-erro { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
    .protheus-filters .filter-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
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
    tr.row-edi-ok { background: #f0fdf4 !important; }
    tr.row-edi-rejeitado { background: #ffedd5 !important; }
    tr.row-edi-erro { background: #fee2e2 !important; }
    .pagination { display: flex; align-items: center; gap: 14px; margin-top: 12px; flex-wrap: wrap; font-size: .88rem; }
    .pagination a { font-weight: bold; }
    @media (max-width: 1100px) {
        .protheus-filters .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>
