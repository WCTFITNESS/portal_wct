<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$summary = null;
$downloadFile = null;
$limit = max(1, min(1000, (int) ($_POST['limit'] ?? 200)));
$dateFrom = trim((string) ($_POST['date_from'] ?? ''));
$dateTo = trim((string) ($_POST['date_to'] ?? ''));
$sku = trim((string) ($_POST['sku'] ?? ''));
$tipo = trim((string) ($_POST['tipo'] ?? 'todos'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['form_type'] ?? '') === 'ml_ads_report') {
    try {
        $result = $app['mlAdsReportService']->generateReport($limit, [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'sku' => $sku,
            'tipo' => $tipo,
        ]);
        $summary = $result;
        $downloadFile = (string) ($result['file_name'] ?? '');
        $feedback = 'Relatorio gerado com sucesso.';
    } catch (Throwable $e) {
        $feedback = 'Erro: ' . $e->getMessage();
        $feedbackClass = 'err';
    }
}
?>
<section class="card">
    <h1>Relatorio de Anuncios ML</h1>
    <p>Extrai anuncios da conta vendedora no Mercado Livre e gera planilha Excel.</p>
    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="form_type" value="ml_ads_report">
        <label>Quantidade maxima de anuncios (1 a 1000)</label>
        <input type="number" name="limit" min="1" max="1000" value="<?= htmlspecialchars((string) $limit) ?>">
        <label>Data inicial (last_updated/date_created)</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        <label>Data final (last_updated/date_created)</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        <label>Filtro SKU (contém)</label>
        <input type="text" name="sku" value="<?= htmlspecialchars($sku) ?>" placeholder="Ex.: 10100052">
        <label>Tipo</label>
        <select name="tipo">
            <option value="todos" <?= $tipo === 'todos' ? 'selected' : '' ?>>Todos</option>
            <option value="premium" <?= $tipo === 'premium' ? 'selected' : '' ?>>Premium</option>
            <option value="classico" <?= $tipo === 'classico' ? 'selected' : '' ?>>Classico</option>
        </select>
        <button type="submit">Gerar relatorio</button>
    </form>

    <?php if ($downloadFile !== '' && is_array($summary)): ?>
        <p style="margin-top:12px;">
            IDs consultados: <strong><?= htmlspecialchars((string) ($summary['total_ids'] ?? 0)) ?></strong> |
            Linhas exportadas: <strong><?= htmlspecialchars((string) ($summary['total_rows'] ?? 0)) ?></strong> |
            Linhas apos filtros: <strong><?= htmlspecialchars((string) ($summary['matched_rows'] ?? 0)) ?></strong>
        </p>
        <p>
            <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=ml-ads-report&download=' . urlencode($downloadFile))) ?>">
                Baixar Excel de anuncios
            </a>
        </p>
    <?php endif; ?>
</section>

