<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$monitor = null;
$today = new DateTimeImmutable('now');
$monthStart = $today->modify('first day of this month');
$dateStart = trim((string) ($_GET['start_date'] ?? $monthStart->format('Y-m-d')));
$dateEnd = trim((string) ($_GET['end_date'] ?? $today->format('Y-m-d')));
$limit = max(1, min(5000, (int) ($_GET['limit'] ?? 1000)));

try {
    $monitor = $app['lexosOrderMonitorService']->monitorPeriod($dateStart, $dateEnd, $limit);
} catch (Throwable $exception) {
    $feedback = 'Erro no monitoramento: ' . $exception->getMessage();
    $feedbackClass = 'err';
}
?>
<section class="card">
    <h1>Monitor de Pedidos</h1>
    <p>Monitoramento de todos os pedidos do período. Padrão: mês atual.</p>
    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="get">
        <input type="hidden" name="page" value="monitor-pedidos">

        <label>Data inicial</label>
        <input type="date" name="start_date" value="<?= htmlspecialchars($dateStart) ?>">

        <label>Data final</label>
        <input type="date" name="end_date" value="<?= htmlspecialchars($dateEnd) ?>">

        <label>Limite de linhas da API</label>
        <input type="number" name="limit" min="1" max="5000" value="<?= htmlspecialchars((string) $limit) ?>">

        <button type="submit">Atualizar Monitor</button>
    </form>
</section>

<?php if ($monitor && is_array($monitor['summary'] ?? null)): ?>
    <?php $summary = $monitor['summary']; ?>
    <section class="card">
        <h1>Resumo do período</h1>
        <table>
            <thead>
            <tr>
                <th>Total pedidos</th>
                <th>Em aberto</th>
                <th>Faturados</th>
                <th>Em atraso</th>
                <th>Enviados</th>
                <th>Entregues</th>
                <th>Outros</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><?= htmlspecialchars((string) ($summary['total_pedidos'] ?? 0)) ?></td>
                <td><?= htmlspecialchars((string) ($summary['aberto'] ?? 0)) ?></td>
                <td><?= htmlspecialchars((string) ($summary['faturado'] ?? 0)) ?></td>
                <td><?= htmlspecialchars((string) ($summary['atraso'] ?? 0)) ?></td>
                <td><?= htmlspecialchars((string) ($summary['enviado'] ?? 0)) ?></td>
                <td><?= htmlspecialchars((string) ($summary['entregue'] ?? 0)) ?></td>
                <td><?= htmlspecialchars((string) ($summary['outros'] ?? 0)) ?></td>
            </tr>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h1>Situação atual por pedido</h1>
        <div style="overflow-x:auto;">
            <table style="min-width: 1000px;">
                <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Categoria</th>
                    <th>Status atual</th>
                    <th>Última data</th>
                    <th>Eventos</th>
                    <th>Ação recomendada</th>
                </tr>
                </thead>
                <tbody>
                <?php $orders = is_array($monitor['orders'] ?? null) ? $monitor['orders'] : []; ?>
                <?php if (!$orders): ?>
                    <tr><td colspan="6">Nenhum pedido encontrado para o período selecionado.</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($item['order_id'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['category'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['status'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['date'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['events_count'] ?? 0)) ?></td>
                        <td><?= htmlspecialchars((string) ($item['action'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
