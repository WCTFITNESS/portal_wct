<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$summary = null;
$downloadFile = null;
$previewRows = [];
$previewDisplayRows = [];
$previewTotal = 0;
$previewShown = 0;
const ML_ADS_PREVIEW_MAX_DISPLAY = 500;

$limitRaw = trim((string) ($_REQUEST['limit'] ?? '200'));
$limit = (int) $limitRaw;
if ($limit < 0) {
    $limit = 0;
}
if ($limit > 5000) {
    $limit = 5000;
}
$dateFrom = trim((string) ($_REQUEST['date_from'] ?? ''));
$dateTo = trim((string) ($_REQUEST['date_to'] ?? ''));
$sku = trim((string) ($_REQUEST['sku'] ?? ''));
$tipo = trim((string) ($_REQUEST['tipo'] ?? 'todos'));

$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'sku' => $sku,
    'tipo' => $tipo,
];

$mlAdsRowClass = static function (string $tipoRow): string {
    if ($tipoRow === 'Premium') {
        return 'row-tipo-premium';
    }
    if ($tipoRow === 'Classico') {
        return 'row-tipo-classico';
    }

    return '';
};

$mlAdsFormAction = portal_wct_public_path($baseUrl, 'index.php?page=ml-ads-report');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['form_type'] ?? '') === 'ml_ads_report') {
    try {
        $result = $app['mlAdsReportService']->generateReport($limit, $filters);
        $summary = $result;
        $previewRows = is_array($result['preview'] ?? null) ? $result['preview'] : [];
        $downloadFile = (string) ($result['file_name'] ?? '');
        $feedback = 'Relatorio gerado com sucesso.';
    } catch (Throwable $e) {
        $feedback = 'Erro: ' . $e->getMessage();
        $feedbackClass = 'err';
    }
} elseif (($_REQUEST['run'] ?? '') === '1') {
    try {
        $result = $app['mlAdsReportService']->collectReportRows($limit, $filters);
        $summary = [
            'total_ids' => $result['total_ids'],
            'matched_rows' => $result['matched_rows'],
            'count_by_tipo' => $result['count_by_tipo'],
        ];
        $previewRows = is_array($result['preview'] ?? null) ? $result['preview'] : [];
        $previewTotal = count($previewRows);
        $previewDisplayRows = array_slice($previewRows, 0, ML_ADS_PREVIEW_MAX_DISPLAY);
        $previewShown = count($previewDisplayRows);
        $feedback = $previewTotal > 0
            ? 'Consulta realizada.'
            : 'Nenhum anuncio encontrado com os filtros informados.';
        if ($previewTotal === 0) {
            $feedbackClass = 'err';
        }
    } catch (Throwable $e) {
        $feedback = 'Erro: ' . $e->getMessage();
        $feedbackClass = 'err';
    }
}
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

    <form method="get" action="<?= htmlspecialchars($mlAdsFormAction) ?>" class="protheus-filters" id="ml-ads-filter-form">
        <input type="hidden" name="page" value="ml-ads-report">
        <input type="hidden" name="run" value="1">
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
                <button type="submit">Filtrar</button>
            </label>
        </div>
    </form>

    <?php if (is_array($summary)): ?>
        <div class="protheus-summary-row">
            <p class="protheus-summary">
                Limite: <strong><?= $limit === 0 ? 'Todos' : htmlspecialchars((string) $limit) ?></strong>
                | IDs consultados: <strong><?= (int) ($summary['total_ids'] ?? 0) ?></strong>
                | Exibidos: <strong><?= (int) ($summary['matched_rows'] ?? $previewTotal) ?></strong>
                <?php if ($previewShown > 0 && $previewTotal > $previewShown): ?>
                    | Na tela: <strong><?= $previewShown ?></strong> de <?= $previewTotal ?>
                <?php endif; ?>
                <?php if (!empty($summary['count_by_tipo']) && is_array($summary['count_by_tipo'])): ?>
                    | Premium: <strong><?= (int) ($summary['count_by_tipo']['Premium'] ?? 0) ?></strong>
                    | Classico: <strong><?= (int) ($summary['count_by_tipo']['Classico'] ?? 0) ?></strong>
                    <?php if (($summary['count_by_tipo']['Outros'] ?? 0) > 0): ?>
                        | Outros: <strong><?= (int) $summary['count_by_tipo']['Outros'] ?></strong>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <?php if ($previewTotal > 0): ?>
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

    <?php if ($previewShown > 0): ?>
        <form method="post" action="<?= htmlspecialchars($mlAdsFormAction) ?>" id="ml-ads-export-form" class="ml-ads-export-hidden">
            <input type="hidden" name="form_type" value="ml_ads_report">
            <input type="hidden" name="limit" value="<?= htmlspecialchars((string) $limit) ?>">
            <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            <input type="hidden" name="sku" value="<?= htmlspecialchars($sku) ?>">
            <input type="hidden" name="tipo" value="<?= htmlspecialchars($tipo) ?>">
        </form>

        <?php if ($previewTotal > $previewShown): ?>
            <p class="protheus-summary" style="margin:8px 0 4px;">
                Mostrando os primeiros <?= (int) $previewShown ?> anuncios na tela.
                Use <strong>Exportar Excel</strong> para obter a lista completa (<?= (int) $previewTotal ?>).
            </p>
        <?php endif; ?>

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
                    <?php foreach ($previewDisplayRows as $row): ?>
                        <?php
                        $tipoRow = (string) ($row['tipo'] ?? '');
                        $rowClass = $mlAdsRowClass($tipoRow);
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
    <?php elseif (is_array($summary) && ($_REQUEST['run'] ?? '') === '1'): ?>
        <div class="table-wrap">
            <table class="protheus-table">
                <tbody>
                    <tr><td colspan="11">Nenhum registro com os filtros informados.</td></tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<style>
    .legend-tipo-premium { background: #fef9c3; color: #713f12; border: 1px solid #fde047; }
    .legend-tipo-classico { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
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
    .ml-ads-export-hidden { display: none; }
</style>
