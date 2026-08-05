<?php

declare(strict_types=1);

use App\Services\TrackingReprocessService;

$feedback = null;
$feedbackClass = 'ok';
$result = null;
$diagnose = null;
$codigo = trim((string) ($_GET['codigo'] ?? $_POST['codigo'] ?? ''));
$acao = trim((string) ($_POST['acao'] ?? 'integrar'));
$shouldRun = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$shouldDiagnose = isset($_GET['diagnosticar']) && $codigo !== '';
$forceReindex = $acao === 'reindexar';

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

if ($shouldDiagnose) {
    try {
        $ch = curl_init($trackingApiBase . '/api/webhook/reprocess-diagnose?codigo=' . rawurlencode($codigo));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $diagnose = is_string($raw) ? json_decode($raw, true) : null;
    } catch (Throwable $exception) {
        $feedback = 'Falha no diagnóstico: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

if ($shouldRun) {
    try {
        $result = $forceReindex
            ? $trackingReprocessService->reindexByCodigo($codigo)
            : $trackingReprocessService->reprocessByCodigo($codigo);

        if (($result['action'] ?? '') === 'already_indexed') {
            $feedback = 'Pedido já estava indexado no Tracking. Use Reindexar para atualizar transportadora e rastreio.';
            $feedbackClass = 'err';
        } elseif (($result['action'] ?? '') === 'reindexed') {
            $ok = false;
            foreach (($result['results'] ?? []) as $row) {
                if (($row['ok'] ?? false) === true) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok && (($result['indexado'] ?? false) === true || isset($result['pedido']['id']))) {
                $ok = true;
            }
            $transp = trim((string) ($result['pedido']['transportadora_nome'] ?? ''));
            $feedback = $ok
                ? ('Reindexado com sucesso' . ($transp !== '' ? ' — ' . $transp : '') . '.')
                : 'Falha ao reindexar. Veja os detalhes abaixo.';
            $feedbackClass = $ok ? 'ok' : 'err';
        } elseif (($result['indexado'] ?? false) === true || isset($result['pedido']['id'])) {
            $feedback = 'Integração forçada com sucesso — pedido indexado no Tracking.';
        } elseif (($result['action'] ?? '') === 'reprocessed' && !empty($result['results'])) {
            $ok = false;
            foreach ($result['results'] as $row) {
                if (($row['ok'] ?? false) === true) {
                    $ok = true;
                    break;
                }
            }
            $feedback = $ok
                ? 'Integração forçada com sucesso. Confira no Dashboard do Tracking.'
                : 'Falha ao forçar integração. Veja os detalhes abaixo.';
            $feedbackClass = $ok ? 'ok' : 'err';
        } else {
            $feedback = 'Integração enviada, mas o pedido ainda não apareceu na verificação.';
            $feedbackClass = 'err';
        }
    } catch (Throwable $exception) {
        $feedback = $exception->getMessage();
        $feedbackClass = 'err';
    }
}

$reprocessUrl = portal_wct_public_path($baseUrl, 'index.php?page=tracking-reprocess');
$diagnoseUrl = static function (string $code) use ($baseUrl): string {
    return portal_wct_public_path($baseUrl, 'index.php?page=tracking-reprocess&diagnosticar=1&codigo=' . rawurlencode($code));
};

?>
<section class="panel">
    <h1>Forçar integração no Tracking</h1>
    <p>
        Busca o pedido na <strong>Lexos</strong> (webhook logs, Hub ou API) e indexa no Tracking WCT.
        Use <strong>Reindexar</strong> quando o pedido já existe mas está sem transportadora ou eventos de rastreio
        (ex.: pedido indexado antes da NF).
    </p>

    <?php if ($feedback !== null): ?>
        <div class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($reprocessUrl) ?>" class="form-grid" style="max-width:720px">
        <div>
            <label for="codigo">Código do pedido (Amazon, marketplace ou Lexos)</label>
            <input id="codigo" type="text" name="codigo" value="<?= htmlspecialchars($codigo) ?>" placeholder="702-1698947-0802649" required>
        </div>
        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center">
            <button type="submit" name="acao" value="integrar">Forçar integração</button>
            <button type="submit" name="acao" value="reindexar" class="btn-secondary" title="Reconsulta Lexos/XML e rastreio mesmo se já indexado">
                Reindexar
            </button>
            <?php if ($codigo !== ''): ?>
                <a class="btn-secondary" href="<?= htmlspecialchars($diagnoseUrl($codigo)) ?>">Diagnosticar</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (is_array($diagnose)): ?>
        <details open style="margin-top:1rem">
            <summary>Diagnóstico — <?= htmlspecialchars($codigo) ?></summary>
            <?php if (!empty($diagnose['motivo_provavel'])): ?>
                <p><strong>Motivo provável:</strong> <?= htmlspecialchars((string) $diagnose['motivo_provavel']) ?></p>
            <?php endif; ?>
            <pre style="white-space:pre-wrap;word-break:break-word"><?= htmlspecialchars(json_encode($diagnose, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '') ?></pre>
        </details>
    <?php endif; ?>

    <?php if (is_array($result)): ?>
        <details open style="margin-top:1rem">
            <summary>Detalhes da execução</summary>
            <pre style="white-space:pre-wrap;word-break:break-word"><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '') ?></pre>
        </details>
    <?php endif; ?>
</section>
