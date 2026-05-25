<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$summary = null;
$downloadFile = null;
$previewRows = [];
$limitRaw = trim((string) ($_POST['limit'] ?? $_GET['limit'] ?? '200'));
$limit = (int) $limitRaw;
if ($limit < 0) {
    $limit = 0;
}
if ($limit > 5000) {
    $limit = 5000;
}
$dateFrom = trim((string) ($_POST['date_from'] ?? $_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_POST['date_to'] ?? $_GET['date_to'] ?? ''));
$sku = trim((string) ($_POST['sku'] ?? $_GET['sku'] ?? ''));
$tipo = trim((string) ($_POST['tipo'] ?? $_GET['tipo'] ?? 'todos'));

$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'sku' => $sku,
    'tipo' => $tipo,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = (string) ($_POST['form_type'] ?? '');
    try {
        if ($formType === 'ml_ads_report') {
            $result = $app['mlAdsReportService']->generateReport($limit, $filters);
            $summary = $result;
            $previewRows = is_array($result['preview'] ?? null) ? $result['preview'] : [];
            $downloadFile = (string) ($result['file_name'] ?? '');
            $feedback = 'Relatorio gerado com sucesso.';
        } elseif ($formType === 'ml_ads_preview') {
            $result = $app['mlAdsReportService']->collectReportRows($limit, $filters);
            $summary = [
                'total_ids' => $result['total_ids'],
                'matched_rows' => $result['matched_rows'],
                'count_by_tipo' => $result['count_by_tipo'],
            ];
            $previewRows = $result['preview'];
            $feedback = count($previewRows) > 0
                ? 'Consulta realizada.'
                : 'Nenhum anuncio encontrado com os filtros informados.';
            if (count($previewRows) === 0) {
                $feedbackClass = 'err';
            }
        }
    } catch (Throwable $e) {
        $feedback = 'Erro: ' . $e->getMessage();
        $feedbackClass = 'err';
    }
}

$mlAdsFormAction = portal_wct_public_path($baseUrl, 'index.php?page=ml-ads-report');
?>
<section class="card protheus-monitor-card">
    <h1>Relatorio de Anuncios ML</h1>
    <p>Lista anuncios da conta vendedora no Mercado Livre. Filtre, visualize na tabela e exporte para Excel.</p>

    <div class="protheus-legend">
        <span class="legend-item legend-tipo-premium">Premium (gold_pro)</span>
        <span class="legend-item legend-tipo-classico">Classico (gold_special, silver…)</span>
    </div>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($mlAdsFormAction) ?>" class="protheus-filters" id="ml-ads-filter-form">
        <input type="hidden" name="form_type" value="ml_ads_preview" id="ml-ads-form-type">
        <div class="filter-grid">
            <label>Limite (0 = todos)
                <input type="number" name="limit" min="0" max="5000" value="<?= htmlspecialchars((string) $limit) ?>">
            </label>
            <label>Data inicial
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </label>
            <label>Data final
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            </label>
            <label>SKU (contém)
                <input type="text" name="sku" value="<?= htmlspecialchars($sku) ?>" placeholder="Ex.: 10100052">
            </label>
            <label>Tipo
                <select name="tipo">
                    <option value="todos" <?= $tipo === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="premium" <?= $tipo === 'premium' ? 'selected' : '' ?>>Premium</option>
                    <option value="classico" <?= $tipo === 'classico' ? 'selected' : '' ?>>Classico</option>
                </select>
            </label>
            <label class="filter-actions-label">&nbsp;
                <button type="submit" onclick="document.getElementById('ml-ads-form-type').value='ml_ads_preview'">
                    Filtrar
                </button>
            </label>
        </div>
    </form>

    <?php if (is_array($summary)): ?>
        <div class="protheus-summary-row">
            <p class="protheus-summary">
                Limite: <strong><?= $limit === 0 ? 'Todos' : htmlspecialchars((string) $limit) ?></strong>
                | IDs consultados: <strong><?= (int) ($summary['total_ids'] ?? 0) ?></strong>
                | Exibidos: <strong><?= (int) ($summary['matched_rows'] ?? count($previewRows)) ?></strong>
                <?php if (!empty($summary['count_by_tipo']) && is_array($summary['count_by_tipo'])): ?>
                    | Premium: <strong><?= (int) ($summary['count_by_tipo']['Premium'] ?? 0) ?></strong>
                    | Classico: <strong><?= (int) ($summary['count_by_tipo']['Classico'] ?? 0) ?></strong>
                    <?php if (($summary['count_by_tipo']['Outros'] ?? 0) > 0): ?>
                        | Outros: <strong><?= (int) $summary['count_by_tipo']['Outros'] ?></strong>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <?php if ($previewRows !== []): ?>
                <button
                    type="submit"
                    form="ml-ads-export-form"
                    class="btn-export-xlsx btn-export-xlsx-submit"
                >Exportar Excel</button>
            <?php endif; ?>
            <?php if ($downloadFile !== ''): ?>
                <a
                    class="btn-export-xlsx"
                    href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=ml-ads-report&download=' . urlencode($downloadFile))) ?>"
                >Baixar Excel</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($previewRows !== []): ?>
        <form method="post" action="<?= htmlspecialchars($mlAdsFormAction) ?>" id="ml-ads-export-form" class="ml-ads-export-hidden">
            <input type="hidden" name="form_type" value="ml_ads_report">
            <input type="hidden" name="limit" value="<?= htmlspecialchars((string) $limit) ?>">
            <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            <input type="hidden" name="sku" value="<?= htmlspecialchars($sku) ?>">
            <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo) ?>">
        </form>

        <div class="table-wrap">
            <table class="protheus-table">
                <thead>
                    <tr>
                        <th>MLB</th>
                        <th>Titulo</th>
                        <th>Preco De</th>
                        <th>Preco Por</th>
                        <th>Status</th>
                        <th>SKU</th>
                        <th>Tipo</th>
                        <th>listing_type_id</th>
                        <th>Full</th>
                        <th>Estoque</th>
                        <th>Vendas</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewRows as $row): ?>
                        <?php
                        $tipoRow = (string) ($row['tipo'] ?? '');
                        $rowClass = match ($tipoRow) {
                            'Premium' => 'row-tipo-premium',
                            'Classico' => 'row-tipo-classico',
                            default => '',
                        };
                        ?>
                        <tr class="<?= htmlspecialchars($rowClass) ?>">
                            <td><?= htmlspecialchars((string) ($row['mlb'] ?? '')) ?></td>
                            <td class="cell-titulo" title="<?= htmlspecialchars((string) ($row['titulo'] ?? '')) ?>">
                                <?= htmlspecialchars((string) ($row['titulo'] ?? '')) ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($row['preco_de'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['preco_por'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['status'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['sku'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($tipoRow) ?></td>
                            <td><code class="cell-code"><?= htmlspecialchars((string) ($row['listing_type_id'] ?? '')) ?></code></td>
                            <td><?= htmlspecialchars((string) ($row['full'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['estoque'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($row['vendas'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif (is_array($summary) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'): ?>
        <div class="table-wrap">
            <table class="protheus-table">
                <tbody>
                    <tr><td>Nenhum registro com os filtros informados.</td></tr>
                </tbody>
            </table>
        </div>
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
    .legend-tipo-premium { background: #fef9c3; color: #713f12; border: 1px solid #fde047; }
    .legend-tipo-classico { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
    .protheus-filters .filter-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px 14px;
        margin-top: 6px;
        align-items: end;
    }
    .protheus-filters label { margin-top: 0; font-weight: bold; font-size: .85rem; }
    .protheus-filters input, .protheus-filters select { margin-top: 4px; }
    .protheus-filters button { margin-top: 0; width: 100%; }
    .filter-actions-label button { margin-top: 4px; }
    .protheus-summary-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 12px 0 6px;
    }
    .protheus-summary { margin: 0; color: var(--wct-muted); font-size: .88rem; }
    a.btn-export-xlsx,
    button.btn-export-xlsx-submit {
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
        cursor: pointer;
        font-family: inherit;
    }
    a.btn-export-xlsx:hover,
    button.btn-export-xlsx-submit:hover:not(:disabled) {
        background: #f5b700;
        color: #111111;
    }
    button.btn-export-xlsx-submit:disabled {
        opacity: .55;
        cursor: not-allowed;
    }
    .ml-ads-export-hidden { display: none; }
    .table-wrap {
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        border: 1px solid var(--wct-border);
        border-radius: 6px;
        margin-top: 6px;
        max-height: min(70vh, 720px);
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
    tr.row-tipo-premium { background: #fffbeb; }
    tr.row-tipo-classico { background: #eff6ff; }
    .protheus-table .cell-titulo {
        max-width: 320px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .protheus-table .cell-code {
        font-size: .75rem;
        background: #f1f5f9;
        padding: 1px 4px;
        border-radius: 3px;
    }
    @media (max-width: 1100px) {
        .protheus-filters .filter-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
</style>
