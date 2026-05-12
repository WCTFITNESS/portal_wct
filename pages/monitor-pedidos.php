<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$monitor = null;
$timelineDetail = null;
$today = new DateTimeImmutable('now');
$monthStart = $today->modify('first day of this month');
$dateStart = trim((string) ($_GET['start_date'] ?? $monthStart->format('Y-m-d')));
$dateEnd = trim((string) ($_GET['end_date'] ?? $today->format('Y-m-d')));
$limit = max(1, min(5000, (int) ($_GET['limit'] ?? 1000)));
$orderQuery = trim((string) ($_GET['order_query'] ?? ''));
$selectedOrderId = trim((string) ($_GET['order_id'] ?? ''));
$usedDashboardExample = (($_GET['use_dashboard_example'] ?? '') === '1');

if ($usedDashboardExample && $orderQuery === '') {
    try {
        $example = $app['lexosOrderMonitorService']->getRecentOrderExample($dateStart, $dateEnd);
        if ($example !== null && $example !== '') {
            $orderQuery = $example;
            $feedback = 'Exemplo carregado automaticamente a partir dos dados da Lexos.';
        } else {
            $feedback = 'Nenhum pedido recente encontrado no período para usar como exemplo.';
            $feedbackClass = 'err';
        }
    } catch (Throwable $exception) {
        $feedback = 'Erro ao carregar exemplo: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

try {
    $monitor = $app['lexosOrderMonitorService']->monitorPeriod($dateStart, $dateEnd, $limit);
} catch (Throwable $exception) {
    $feedback = 'Erro no monitoramento: ' . $exception->getMessage();
    $feedbackClass = 'err';
}

if ($orderQuery !== '') {
    try {
        $timelineDetail = $app['lexosOrderMonitorService']->findOrderTimeline($orderQuery, $dateStart, $dateEnd, min(500, $limit));
    } catch (Throwable $exception) {
        if ($feedback === null) {
            $feedback = 'Erro na timeline do pedido: ' . $exception->getMessage();
            $feedbackClass = 'err';
        }
    }
}

$timelineOrders = [];
if (is_array($timelineDetail['timelines'] ?? null) && $timelineDetail['timelines'] !== []) {
    $timelineOrders = $timelineDetail['timelines'];
} elseif ($monitor && $selectedOrderId !== '' && is_array($monitor['timelines'][$selectedOrderId] ?? null)) {
    $timelineOrders = [$selectedOrderId => $monitor['timelines'][$selectedOrderId]];
}

$orders = is_array($monitor['orders'] ?? null) ? $monitor['orders'] : [];
if ($orderQuery !== '' && $orders !== []) {
    $orders = array_values(array_filter(
        $orders,
        static fn (array $item): bool => stripos((string) ($item['order_id'] ?? ''), $orderQuery) !== false
    ));
}

$monitorUrl = static function (array $extra = []) use ($baseUrl, $dateStart, $dateEnd, $limit, $orderQuery): string {
    $query = array_merge([
        'page' => 'monitor-pedidos',
        'start_date' => $dateStart,
        'end_date' => $dateEnd,
        'limit' => (string) $limit,
    ], $extra);

    if (!array_key_exists('order_query', $extra) && $orderQuery !== '') {
        $query['order_query'] = $orderQuery;
    }

    return portal_wct_public_path($baseUrl, 'index.php?' . http_build_query($query));
};

$categoryLabel = static function (string $category): string {
    return match ($category) {
        'aberto' => 'Em aberto',
        'faturado' => 'Faturado',
        'atraso' => 'Em atraso',
        'enviado' => 'Enviado',
        'entregue' => 'Entregue',
        default => 'Outros',
    };
};

$timelineAnchor = static function (string $orderId): string {
    $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $orderId) ?? 'pedido';

    return 'timeline-' . trim($safe, '-');
};
?>
<style>
    .monitor-filters {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px 16px;
        align-items: end;
    }
    .monitor-filters label {
        display: block;
        margin-bottom: 4px;
        font-size: .9rem;
        color: var(--wct-muted);
    }
    .monitor-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }
    .monitor-actions .btn-secondary {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 10px 14px;
        border-radius: 6px;
        border: 1px solid var(--wct-border);
        background: #fff;
        color: var(--wct-text);
        text-decoration: none;
        font-size: .92rem;
    }
    .monitor-actions .btn-secondary:hover {
        border-color: #cbd5e1;
        background: #f9fafb;
    }
    .status-pill {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 999px;
        font-size: .78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    .status-pill--aberto { background: #fef3c7; color: #92400e; }
    .status-pill--faturado { background: #dbeafe; color: #1d4ed8; }
    .status-pill--atraso { background: #fee2e2; color: #b91c1c; }
    .status-pill--enviado { background: #e0e7ff; color: #4338ca; }
    .status-pill--entregue { background: #dcfce7; color: #166534; }
    .status-pill--outros { background: #f3f4f6; color: #374151; }
    .status-pill--current { background: #111827; color: #fff; }
    .order-timeline {
        list-style: none;
        margin: 0;
        padding: 0 0 0 18px;
        border-left: 2px solid var(--wct-border);
    }
    .order-timeline__item {
        position: relative;
        margin: 0 0 18px 0;
        padding-left: 18px;
    }
    .order-timeline__item::before {
        content: "";
        position: absolute;
        left: -25px;
        top: 6px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: #fff;
        border: 3px solid #9ca3af;
    }
    .order-timeline__item--current::before {
        border-color: var(--wct-primary);
        box-shadow: 0 0 0 4px rgba(213, 0, 0, .12);
    }
    .order-timeline__item--aberto::before { border-color: #d97706; }
    .order-timeline__item--faturado::before { border-color: #2563eb; }
    .order-timeline__item--atraso::before { border-color: #dc2626; }
    .order-timeline__item--enviado::before { border-color: #4f46e5; }
    .order-timeline__item--entregue::before { border-color: #16a34a; }
    .order-timeline__meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 12px;
        align-items: center;
        margin-bottom: 6px;
    }
    .order-timeline__date {
        color: var(--wct-muted);
        font-size: .88rem;
    }
    .order-timeline__action {
        margin: 0;
        color: var(--wct-text);
        line-height: 1.45;
    }
    .timeline-card {
        border: 1px solid var(--wct-border);
        border-radius: 10px;
        padding: 16px;
        margin-bottom: 16px;
        background: #fff;
    }
    .timeline-card__header {
        display: flex;
        flex-wrap: wrap;
        gap: 8px 16px;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 14px;
        padding-bottom: 12px;
        border-bottom: 1px solid var(--wct-border);
    }
    .timeline-card__title {
        margin: 0;
        font-size: 1.05rem;
    }
    .timeline-card__stopped {
        margin: 0 0 14px 0;
        color: var(--wct-muted);
        font-size: .92rem;
    }
    .timeline-link {
        color: var(--wct-primary);
        text-decoration: none;
        font-weight: 700;
    }
    .timeline-link:hover {
        text-decoration: underline;
    }
</style>

<section class="card">
    <h1>Monitor de Pedidos</h1>
    <p>Visão gerencial do período e timeline por pedido com base nos status retornados pela API Lexos.</p>
    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="get">
        <input type="hidden" name="page" value="monitor-pedidos">

        <div class="monitor-filters">
            <div>
                <label for="start_date">Data inicial</label>
                <input id="start_date" type="date" name="start_date" value="<?= htmlspecialchars($dateStart) ?>">
            </div>

            <div>
                <label for="end_date">Data final</label>
                <input id="end_date" type="date" name="end_date" value="<?= htmlspecialchars($dateEnd) ?>">
            </div>

            <div>
                <label for="order_query">Pedido (timeline)</label>
                <input id="order_query" type="text" name="order_query" value="<?= htmlspecialchars($orderQuery) ?>" placeholder="Número ou código do pedido">
            </div>

            <div>
                <label for="limit">Limite de linhas da API</label>
                <input id="limit" type="number" name="limit" min="1" max="5000" value="<?= htmlspecialchars((string) $limit) ?>">
            </div>

            <div>
                <label>&nbsp;</label>
                <button type="submit">Atualizar Monitor</button>
            </div>
        </div>

        <div class="monitor-actions">
            <a class="btn-secondary" href="<?= htmlspecialchars($monitorUrl(['use_dashboard_example' => '1'])) ?>">Usar exemplo do Dashboard</a>
            <?php if ($orderQuery !== '' || $selectedOrderId !== ''): ?>
                <a class="btn-secondary" href="<?= htmlspecialchars($monitorUrl(['order_query' => '', 'order_id' => ''])) ?>">Limpar timeline</a>
            <?php endif; ?>
        </div>
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
            <table style="min-width: 1100px;">
                <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Categoria</th>
                    <th>Status atual</th>
                    <th>Última data</th>
                    <th>Eventos</th>
                    <th>Ação recomendada</th>
                    <th>Timeline</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$orders): ?>
                    <tr><td colspan="7">Nenhum pedido encontrado para o período selecionado.</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $item): ?>
                    <?php
                    $orderId = (string) ($item['order_id'] ?? '');
                    $category = (string) ($item['category'] ?? 'outros');
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($orderId) ?></td>
                        <td><span class="status-pill status-pill--<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($categoryLabel($category)) ?></span></td>
                        <td><?= htmlspecialchars((string) ($item['status'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['date'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['events_count'] ?? 0)) ?></td>
                        <td><?= htmlspecialchars((string) ($item['action'] ?? '')) ?></td>
                        <td>
                            <a class="timeline-link" href="<?= htmlspecialchars($monitorUrl(['order_id' => $orderId, 'order_query' => ''])) ?>#<?= htmlspecialchars($timelineAnchor($orderId)) ?>">Ver timeline</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($timelineOrders !== []): ?>
    <section class="card" id="timeline-pedido">
        <h1>Timeline do pedido</h1>
        <p>Eventos ordenados por data. O último status indica onde o pedido parou e qual ação seguir.</p>

        <?php foreach ($timelineOrders as $orderId => $events): ?>
            <?php
            if (!is_array($events) || $events === []) {
                continue;
            }

            $lastEvent = $events[count($events) - 1];
            $lastCategory = (string) ($lastEvent['category'] ?? 'outros');
            ?>
            <article class="timeline-card" id="<?= htmlspecialchars($timelineAnchor((string) $orderId)) ?>">
                <div class="timeline-card__header">
                    <h2 class="timeline-card__title">Pedido <?= htmlspecialchars((string) $orderId) ?></h2>
                    <span class="status-pill status-pill--<?= htmlspecialchars($lastCategory) ?>"><?= htmlspecialchars($categoryLabel($lastCategory)) ?></span>
                </div>
                <p class="timeline-card__stopped">
                    Parou em <strong><?= htmlspecialchars((string) ($lastEvent['status'] ?? '')) ?></strong>
                    <?php if (($lastEvent['date'] ?? '') !== ''): ?>
                        em <?= htmlspecialchars((string) $lastEvent['date']) ?>
                    <?php endif; ?>
                </p>

                <ol class="order-timeline">
                    <?php foreach ($events as $index => $event): ?>
                        <?php
                        $eventCategory = (string) ($event['category'] ?? 'outros');
                        $isCurrent = $index === count($events) - 1;
                        $itemClass = 'order-timeline__item order-timeline__item--' . $eventCategory;
                        if ($isCurrent) {
                            $itemClass .= ' order-timeline__item--current';
                        }
                        ?>
                        <li class="<?= htmlspecialchars($itemClass) ?>">
                            <div class="order-timeline__meta">
                                <strong><?= htmlspecialchars((string) ($event['status'] ?? '')) ?></strong>
                                <?php if (($event['date'] ?? '') !== ''): ?>
                                    <span class="order-timeline__date"><?= htmlspecialchars((string) $event['date']) ?></span>
                                <?php endif; ?>
                                <span class="status-pill status-pill--<?= htmlspecialchars($eventCategory) ?>"><?= htmlspecialchars($categoryLabel($eventCategory)) ?></span>
                                <?php if ($isCurrent): ?>
                                    <span class="status-pill status-pill--current">Atual</span>
                                <?php endif; ?>
                            </div>
                            <p class="order-timeline__action"><?= htmlspecialchars((string) ($event['action'] ?? '')) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </article>
        <?php endforeach; ?>
    </section>
<?php elseif ($orderQuery !== '' || $selectedOrderId !== ''): ?>
    <section class="card">
        <h1>Timeline do pedido</h1>
        <p>Nenhum evento encontrado para o pedido informado no período selecionado.</p>
    </section>
<?php endif; ?>
