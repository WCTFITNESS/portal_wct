<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$monitor = null;
$today = new DateTimeImmutable('now');
$monthStart = $today->modify('first day of this month');
$orderQuery = trim((string) ($_GET['order_query'] ?? ''));
$dateStart = trim((string) ($_GET['start_date'] ?? $monthStart->format('Y-m-d')));
$dateEnd = trim((string) ($_GET['end_date'] ?? $today->format('Y-m-d')));
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
$usedDashboardExample = (($_GET['use_dashboard_example'] ?? '') === '1');

if ($usedDashboardExample && $orderQuery === '') {
    try {
        $example = $app['lexosOrderMonitorService']->getRecentOrderExample($dateStart, $dateEnd);
        if ($example !== null && $example !== '') {
            $orderQuery = $example;
            $feedback = 'Exemplo carregado automaticamente a partir dos dados da Lexos (Dashboard).';
        } else {
            $feedback = 'Não foi encontrado pedido de exemplo no período informado.';
            $feedbackClass = 'err';
        }
    } catch (Throwable $exception) {
        $feedback = 'Erro ao buscar exemplo do Dashboard: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

if ($orderQuery !== '') {
    try {
        $monitor = $app['lexosOrderMonitorService']->findOrderTimeline($orderQuery, $dateStart, $dateEnd, $limit);
    } catch (Throwable $exception) {
        $feedback = 'Erro no monitoramento: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}
?>
<style>
    .timeline-list { list-style: none; margin: 12px 0 0 0; padding: 0; border-left: 3px solid #e5e7eb; }
    .timeline-item { position: relative; margin: 0 0 12px 0; padding: 0 0 0 14px; }
    .timeline-item::before {
        content: '';
        width: 10px;
        height: 10px;
        border-radius: 999px;
        background: #d50000;
        position: absolute;
        left: -7px;
        top: 4px;
    }
    .timeline-date { color: #6b7280; font-size: .85rem; }
    .timeline-status { font-weight: bold; margin: 2px 0 4px 0; }
    .timeline-action { color: #1f2937; font-size: .92rem; }
</style>

<section class="card">
    <h1>Monitor de Pedidos</h1>
    <p>Consulta status na API Lexos para identificar em qual etapa o pedido parou e qual ação tomar.</p>
    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="get">
        <input type="hidden" name="page" value="monitor-pedidos">
        <label>Número do pedido / código para busca</label>
        <input type="text" name="order_query" value="<?= htmlspecialchars($orderQuery) ?>" placeholder="Ex.: 20000012345" required>

        <label>Data inicial</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($dateStart) ?>">

        <label>Data final</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($dateEnd) ?>">

        <label>Limite de linhas da API</label>
        <input type="number" name="limit" min="1" max="500" value="<?= htmlspecialchars((string) $limit) ?>">

        <button type="submit">Monitorar Pedido</button>
    </form>

    <form method="get">
        <input type="hidden" name="page" value="monitor-pedidos">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars($dateStart) ?>">
        <input type="hidden" name="end_date" value="<?= htmlspecialchars($dateEnd) ?>">
        <input type="hidden" name="limit" value="<?= htmlspecialchars((string) $limit) ?>">
        <input type="hidden" name="use_dashboard_example" value="1">
        <button type="submit">Usar exemplo do Dashboard</button>
    </form>
</section>

<?php if ($monitor && is_array($monitor['timelines'] ?? null) && $monitor['timelines'] !== []): ?>
    <section class="card">
        <h1>Timeline dos pedidos</h1>
        <?php foreach ($monitor['timelines'] as $orderId => $events): ?>
            <?php $last = $events[count($events) - 1] ?? null; ?>
            <div style="border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:10px 0;">
                <h2 style="margin:0 0 8px 0;font-size:1.05rem;">Pedido <?= htmlspecialchars((string) $orderId) ?></h2>
                <?php if ($last): ?>
                    <p style="margin:0 0 8px 0;">
                        <strong>Parou em:</strong> <?= htmlspecialchars((string) ($last['status'] ?? '')) ?><br>
                        <strong>Ação recomendada:</strong> <?= htmlspecialchars((string) ($last['action'] ?? '')) ?>
                    </p>
                <?php endif; ?>

                <ul class="timeline-list">
                    <?php foreach ($events as $event): ?>
                        <li class="timeline-item">
                            <div class="timeline-date"><?= htmlspecialchars((string) ($event['date'] ?? 'Sem data')) ?></div>
                            <div class="timeline-status"><?= htmlspecialchars((string) ($event['status'] ?? 'Sem status')) ?></div>
                            <div class="timeline-action"><?= htmlspecialchars((string) ($event['action'] ?? '')) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="card">
        <h1>Dados brutos da consulta Lexos</h1>
        <div style="overflow-x:auto;">
            <table style="min-width: 1200px;">
                <thead>
                <tr>
                    <th>JSON</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($monitor['rows'] ?? []) as $row): ?>
                    <tr>
                        <td><pre style="margin:0;white-space:pre-wrap;word-break:break-word;"><?= htmlspecialchars((string) json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php elseif ($orderQuery !== '' && !$feedback): ?>
    <section class="card">
        <p>Nenhum status encontrado para o pedido informado no período selecionado.</p>
    </section>
<?php endif; ?>
