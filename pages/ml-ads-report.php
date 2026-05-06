<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$summary = null;
$downloadFile = null;
$limit = max(1, min(1000, (int) ($_POST['limit'] ?? 200)));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['form_type'] ?? '') === 'ml_ads_report') {
    try {
        $result = $app['mlAdsReportService']->generateReport($limit);
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
        <button type="submit">Gerar relatorio</button>
    </form>

    <?php if ($downloadFile !== '' && is_array($summary)): ?>
        <p style="margin-top:12px;">
            IDs consultados: <strong><?= htmlspecialchars((string) ($summary['total_ids'] ?? 0)) ?></strong> |
            Linhas exportadas: <strong><?= htmlspecialchars((string) ($summary['total_rows'] ?? 0)) ?></strong>
        </p>
        <p>
            <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=ml-ads-report&download=' . urlencode($downloadFile))) ?>">
                Baixar Excel de anuncios
            </a>
        </p>
    <?php endif; ?>
</section>

