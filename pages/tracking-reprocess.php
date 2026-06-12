<?php

declare(strict_types=1);

use App\Services\TrackingReprocessService;

$feedback = null;
$feedbackClass = 'ok';
$result = null;
$codigo = trim((string) ($_GET['codigo'] ?? $_POST['codigo'] ?? '702-5579998-8073849'));
$shouldRun = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$apiCfg = $app['settingsRepository']->getApiConfig() ?? [];
$trackingDbUrl = trim((string) ($apiCfg['tracking_database_url'] ?? ''));
if ($trackingDbUrl === '') {
    $env = getenv('TRACKING_DATABASE_URL');
    $trackingDbUrl = is_string($env) ? trim($env) : '';
}

$trackingApiBase = preg_replace('#/admin/dashboard$#', '', (string) ($trackingWctUrl ?? '')) ?: 'http://localhost:3001';
$lexosHub = $app['lexosCredentialsService']->isReady() ? $app['lexosHubApiClient'] : null;
$trackingReprocessService = new TrackingReprocessService(
    new \App\Core\TrackingDatabase($trackingDbUrl),
    $trackingApiBase,
    $lexosHub
);

if ($shouldRun) {
    try {
        $result = $trackingReprocessService->reprocessByCodigo($codigo);
        if (($result['action'] ?? '') === 'already_indexed') {
            $feedback = 'Pedido já estava indexado no Tracking.';
        } elseif (($result['indexado'] ?? false) === true) {
            $feedback = 'Pedido reprocessado e indexado com sucesso no Tracking.';
        } else {
            $feedback = 'Webhook enviado ao Tracking, mas o pedido ainda não apareceu na verificação. Veja os detalhes abaixo.';
            $feedbackClass = 'err';
        }
    } catch (Throwable $exception) {
        $feedback = $exception->getMessage();
        $feedbackClass = 'err';
    }
}

?>
<section class="panel">
    <h1>Reprocessar pedido no Tracking</h1>
    <p>
        Localiza o <strong>PedidoId Lexos</strong> em <code>webhook_logs</code> (banco do Tracking)
        e reenvia o webhook para indexar pedidos Amazon e outros que foram ignorados antes da correção.
    </p>

    <?php if ($feedback !== null): ?>
        <div class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=tracking-reprocess')) ?>" class="form-grid" style="max-width:720px">
        <div>
            <label for="codigo">Código do pedido (Amazon, marketplace ou Lexos)</label>
            <input id="codigo" type="text" name="codigo" value="<?= htmlspecialchars($codigo) ?>" placeholder="702-5579998-8073849" required>
        </div>
        <div>
            <button type="submit">Reprocessar no Tracking</button>
        </div>
    </form>

    <?php if (is_array($result)): ?>
        <details open style="margin-top:1rem">
            <summary>Detalhes da execução</summary>
            <pre style="white-space:pre-wrap;word-break:break-word"><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '') ?></pre>
        </details>
    <?php endif; ?>
</section>
