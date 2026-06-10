<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$result = null;
$orderQuery = trim((string) ($_GET['order'] ?? $_POST['order'] ?? '2000016479266634'));

$lexosApiReady = $app['lexosCredentialsService']->isReady();

/** @var \App\Services\LexosExpeditionDiagnosticService $diag */
$diag = $app['lexosExpeditionDiagnosticService'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lexosApiReady) {
    try {
        $result = $diag->diagnose($orderQuery);
    } catch (Throwable $e) {
        $feedback = $e->getMessage();
        $feedbackClass = 'err';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedback = 'Configure credenciais Lexos em Configuração API (portal ou banco Tracking).';
    $feedbackClass = 'err';
}

function lexos_diag_h(mixed $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    if (is_bool($v)) {
        return $v ? 'sim' : 'não';
    }
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '—';
    }

    return (string) $v;
}

?>
<div class="card">
    <h1>Diagnóstico expedição Lexos</h1>
    <p>
        Consulta o pedido na API do <a href="https://app-hub.lexos.com.br/#/expedicao/entrega/lista/?aba=Todos" target="_blank" rel="noopener">Lexos Hub — Expedição</a>
        e lista transportadoras cadastradas. Use para descobrir qual transportadora falta no checkout.
        Para cadastrar, use <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=lexos-transportadoras')) ?>">Transportadoras</a>.
    </p>

    <?php if ($feedback !== null): ?>
        <p class="feedback <?= htmlspecialchars($feedbackClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if (!$lexosApiReady): ?>
        <p class="feedback err">Credenciais Lexos incompletas. Ajuste em <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=api-config&api_tab=lexos')) ?>">Configuração API → Lexos</a> (portal ou URL do banco Tracking).</p>
    <?php endif; ?>

    <form method="post" class="filter-grid" style="max-width: 520px; margin-bottom: 1.5rem;">
        <label>
            Código do pedido (marketplace)
            <input type="text" name="order" value="<?= htmlspecialchars($orderQuery, ENT_QUOTES, 'UTF-8') ?>" required>
        </label>
        <button type="submit" <?= $lexosApiReady ? '' : 'disabled' ?>>Diagnosticar</button>
    </form>

    <?php if (is_array($result)): ?>
        <?php
        $row = is_array($result['order_row'] ?? null) ? $result['order_row'] : [];
        $hints = is_array($result['hints'] ?? null) ? $result['hints'] : [];
        ?>
        <h2>Pedido <?= htmlspecialchars($orderQuery, ENT_QUOTES, 'UTF-8') ?></h2>
        <ul class="lexos-hints">
            <?php foreach ($hints as $hint): ?>
                <li><?= htmlspecialchars((string) $hint, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>

        <h3>Campos relevantes (API Expedição)</h3>
        <table class="table">
            <tbody>
                <?php
                $keys = ['Codigo', 'Pedido', 'Status', 'TransportadoraNome', 'TransportadoraId', 'IdTransportadora', 'DataLimiteEnvio', 'Erro', 'Erros', 'Mensagem'];
                foreach ($keys as $key):
                    if (!array_key_exists($key, $row) && $key !== 'Transportadora') {
                        continue;
                    }
                    ?>
                    <tr>
                        <th><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></th>
                        <td><pre class="lexos-pre"><?= htmlspecialchars(lexos_diag_h($row[$key] ?? null), ENT_QUOTES, 'UTF-8') ?></pre></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (isset($row['Transportadora']) && is_array($row['Transportadora'])): ?>
                    <tr>
                        <th>Transportadora (objeto)</th>
                        <td><pre class="lexos-pre"><?= htmlspecialchars(lexos_diag_h($row['Transportadora']), ENT_QUOTES, 'UTF-8') ?></pre></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h3>Transportadoras cadastradas no Lexos</h3>
        <?php foreach ((array) ($result['transportadora_lists'] ?? []) as $listKey => $list): ?>
            <h4><?= htmlspecialchars((string) $listKey, ENT_QUOTES, 'UTF-8') ?></h4>
            <?php if (!($list['ok'] ?? false)): ?>
                <p class="feedback err"><?= htmlspecialchars((string) ($list['error'] ?? 'Erro'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
                <p><?= (int) ($list['count'] ?? 0) ?> registro(s).</p>
                <?php if (!empty($list['names'])): ?>
                    <ul class="lexos-carrier-list">
                        <?php foreach ($list['names'] as $name): ?>
                            <li><?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <details style="margin-top: 1rem;">
            <summary>JSON bruto (entrega)</summary>
            <pre class="lexos-pre"><?= htmlspecialchars(lexos_diag_h($result['entrega'] ?? []), ENT_QUOTES, 'UTF-8') ?></pre>
        </details>
    <?php endif; ?>
</div>

<style>
    .lexos-hints { margin: 0.75rem 0 1.25rem; padding-left: 1.25rem; }
    .lexos-hints li { margin-bottom: 0.35rem; }
    .lexos-pre { white-space: pre-wrap; word-break: break-word; font-size: 0.8rem; max-height: 280px; overflow: auto; }
    .lexos-carrier-list { columns: 2; font-size: 0.875rem; }
    @media (max-width: 640px) { .lexos-carrier-list { columns: 1; } }
</style>
