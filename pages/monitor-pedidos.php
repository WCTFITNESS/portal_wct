<?php

declare(strict_types=1);

use App\Services\LexosExpeditionLane;
use App\Services\LexosOrderTimelineSupport;

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
$expLane = trim((string) ($_GET['exp_lane'] ?? LexosExpeditionLane::LANE_TODOS));
$laneOrderFlip = array_flip(LexosExpeditionLane::laneOrder());
if (!isset($laneOrderFlip[$expLane])) {
    $expLane = LexosExpeditionLane::LANE_TODOS;
}
$selectedOrderId = trim((string) ($_GET['order_id'] ?? ''));
$usedRecentExample = (($_GET['use_recent_example'] ?? '') === '1');
$webhookService = $app['lexosOrderWebhookService'];
$mercadoLivreMonitorService = $app['mercadoLivreOrderMonitorService'];
$lexosOrderMonitorService = $app['lexosOrderMonitorService'];
$apiCfg = $app['settingsRepository']->getApiConfig();
$lexosApiReady = trim((string) ($apiCfg['lexos_token'] ?? '')) !== ''
    && trim((string) ($apiCfg['lexos_integration_key'] ?? '')) !== '';
$webhookUrl = portal_wct_absolute_url($baseUrl, 'webhooks/lexos-pedidos.php');
$storedEvents = $webhookService->countStoredEvents();
$deliveryStats = $webhookService->getDeliveryStats();
$monitorSource = 'webhook';

if ($usedRecentExample && $orderQuery === '') {
    $example = $webhookService->getRecentOrderExample($dateStart, $dateEnd);
    if ($example === null || $example === '') {
        if ($lexosApiReady) {
            try {
                $example = $lexosOrderMonitorService->getRecentOrderExample($dateStart, $dateEnd);
            } catch (Throwable) {
                $example = null;
            }
        }
    }
    if ($example === null || $example === '') {
        $example = $mercadoLivreMonitorService->getRecentOrderExample($dateStart, $dateEnd);
    }

    if ($example !== null && $example !== '') {
        $orderQuery = $example;
        $feedback = 'Exemplo carregado a partir dos pedidos disponíveis no período.';
    } else {
        $feedback = 'Nenhum pedido encontrado no período para usar como exemplo.';
        $feedbackClass = 'err';
    }
}

try {
    $monitor = $webhookService->monitorPeriod($dateStart, $dateEnd, $limit);
    if ((int) ($monitor['summary']['total_pedidos'] ?? 0) === 0 && $lexosApiReady) {
        try {
            $lexosListMonitor = $lexosOrderMonitorService->monitorPeriod($dateStart, $dateEnd, $limit);
            if ((int) ($lexosListMonitor['summary']['total_pedidos'] ?? 0) > 0) {
                $monitor = $lexosListMonitor;
                $monitor['source'] = 'lexos_api';
                $monitorSource = 'lexos_api';
                $listSrc = (string) ($lexosListMonitor['lexos_list_source'] ?? 'pedido_datasource');
                $feedback = $listSrc === 'entrega_datasource_todos'
                    ? 'Sem eventos de webhook Lexos; lista do período via Expedição Lexos (Entrega/DataSourceTodos), filtrada pelas datas do pedido.'
                    : 'Sem eventos de webhook Lexos; lista do período via Lexos Pedido/DataSource.';
                $feedbackClass = 'ok';
            }
        } catch (Throwable) {
        }
    }
    if ((int) ($monitor['summary']['total_pedidos'] ?? 0) === 0) {
        $mlMonitor = $mercadoLivreMonitorService->monitorPeriod($dateStart, $dateEnd, $limit);
        if ((int) ($mlMonitor['summary']['total_pedidos'] ?? 0) > 0) {
            $monitor = $mlMonitor;
            $monitorSource = 'mercado_livre';
            $feedback = 'Nenhum dado Lexos (webhook/API) no período. Exibindo pedidos do Mercado Livre (até 50 por status).';
            $feedbackClass = 'ok';
        }
    }
} catch (Throwable $exception) {
    $feedback = 'Erro no monitoramento: ' . $exception->getMessage();
    $feedbackClass = 'err';
}

if ($monitor !== null && $orderQuery !== '') {
    $monitor = LexosOrderTimelineSupport::filterSnapshot($monitor, $orderQuery);
    if ((int) ($monitor['summary']['total_pedidos'] ?? 0) === 0 && $lexosApiReady) {
        try {
            $lexosHit = $lexosOrderMonitorService->findOrderTimeline($orderQuery, $dateStart, $dateEnd, min(200, $limit));
            $lexosTimelines = $lexosHit['timelines'] ?? [];
            if ($lexosTimelines !== []) {
                $monitor = array_merge(
                    LexosOrderTimelineSupport::buildMonitorSnapshot($lexosTimelines, $dateStart, $dateEnd),
                    [
                        'source' => 'lexos_api',
                        'rows' => $lexosHit['rows'] ?? [],
                        'lexos_find_source' => (string) ($lexosHit['lexos_source'] ?? ''),
                    ]
                );
                $monitorSource = 'lexos_api';
                $feedback = 'Pedido localizado na Lexos WebAPI (busca direta por código).';
                $feedbackClass = 'ok';
            }
        } catch (Throwable) {
        }
    }
    if ((int) ($monitor['summary']['total_pedidos'] ?? 0) === 0) {
        $directMonitor = $mercadoLivreMonitorService->buildMonitorFromOrderId($orderQuery, $dateStart, $dateEnd);
        if ($directMonitor !== null) {
            $monitor = $directMonitor;
            $monitorSource = 'mercado_livre';
            $feedback = 'Pedido localizado diretamente na API Mercado Livre.';
            $feedbackClass = 'ok';
        }
    }
}

if ($orderQuery !== '') {
    try {
        $timelineDetail = $webhookService->findOrderTimeline($orderQuery, $dateStart, $dateEnd, min(500, $limit));
        $tlEmpty = !is_array($timelineDetail['timelines'] ?? null) || $timelineDetail['timelines'] === [];
        if ($tlEmpty && $lexosApiReady) {
            try {
                $lexosTl = $lexosOrderMonitorService->findOrderTimeline($orderQuery, $dateStart, $dateEnd, min(200, $limit));
                $lexosTls = $lexosTl['timelines'] ?? [];
                if (is_array($lexosTls) && $lexosTls !== []) {
                    $timelineDetail = [
                        'query' => $orderQuery,
                        'timelines' => $lexosTls,
                    ];
                }
            } catch (Throwable) {
            }
        }
        if (!is_array($timelineDetail['timelines'] ?? null) || $timelineDetail['timelines'] === []) {
            $timelineDetail = $mercadoLivreMonitorService->findOrderTimeline($orderQuery, $dateStart, $dateEnd, min(500, $limit));
        }
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
} elseif ($monitor && $orderQuery !== '' && is_array($monitor['timelines'] ?? null)) {
    foreach ($monitor['timelines'] as $orderId => $events) {
        if (stripos((string) $orderId, $orderQuery) !== false) {
            $timelineOrders[$orderId] = $events;
        }
    }
}

$allOrders = ($monitor !== null && is_array($monitor['orders'] ?? null)) ? $monitor['orders'] : [];
$laneCounts = LexosExpeditionLane::countByLane($allOrders);
$orders = $allOrders;
if ($expLane !== LexosExpeditionLane::LANE_TODOS) {
    $orders = array_values(array_filter(
        $allOrders,
        static function (array $item) use ($expLane): bool {
            $status = (string) ($item['status'] ?? '');

            return LexosExpeditionLane::mapLane($status) === $expLane;
        }
    ));
}
$isOrderFilterActive = $orderQuery !== '';

if ($isOrderFilterActive && $monitor && (int) ($monitor['summary']['total_pedidos'] ?? 0) === 0 && $feedbackClass !== 'err') {
    $feedback = 'Nenhum pedido encontrado para o filtro informado no período selecionado.';
    $feedbackClass = 'err';
}

$monitorUrl = static function (array $extra = []) use ($baseUrl, $dateStart, $dateEnd, $limit, $orderQuery, $expLane): string {
    $query = array_merge([
        'page' => 'monitor-pedidos',
        'start_date' => $dateStart,
        'end_date' => $dateEnd,
        'limit' => (string) $limit,
        'exp_lane' => $expLane,
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

$expLaneLabels = LexosExpeditionLane::laneLabels();

$timelineAnchor = static function (string $orderId): string {
    $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $orderId) ?? 'pedido';

    return 'timeline-' . trim($safe, '-');
};

$renderJsonDetails = static function (array $row, string $label = 'Ver JSON'): string {
    if ($row === []) {
        return '-';
    }

    $json = LexosOrderTimelineSupport::encodeRowJson($row);

    return '<details class="json-details"><summary>' . htmlspecialchars($label) . '</summary><pre class="json-details__pre">'
        . htmlspecialchars($json) . '</pre></details>';
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
    .exp-tabs-wrap {
        margin: 0 0 14px 0;
        border-bottom: 1px solid var(--wct-border);
    }
    .exp-tabs {
        display: flex;
        flex-wrap: nowrap;
        gap: 4px;
        overflow-x: auto;
        padding-bottom: 8px;
        -webkit-overflow-scrolling: touch;
    }
    .exp-tabs__link {
        flex: 0 0 auto;
        padding: 8px 12px;
        border-radius: 8px 8px 0 0;
        border: 1px solid transparent;
        border-bottom: none;
        background: #f8fafc;
        color: var(--wct-text);
        text-decoration: none;
        font-size: .88rem;
        white-space: nowrap;
    }
    .exp-tabs__link:hover {
        background: #f1f5f9;
    }
    .exp-tabs__link--active {
        background: #fff;
        border-color: var(--wct-border);
        font-weight: 700;
        color: var(--wct-primary);
    }
    .exp-tabs__count {
        color: var(--wct-muted);
        font-weight: 600;
    }
    .exp-tabs__hint {
        margin: 0 0 12px 0;
        font-size: .86rem;
        color: var(--wct-muted);
        line-height: 1.45;
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
    .json-details {
        margin: 0;
    }
    .json-details summary {
        cursor: pointer;
        color: var(--wct-primary);
        font-weight: 700;
        list-style-position: outside;
    }
    .json-details__pre {
        margin: 8px 0 0 0;
        max-height: 320px;
        overflow: auto;
        background: #111827;
        color: #f9fafb;
        padding: 12px;
        border-radius: 8px;
        font-size: .82rem;
        line-height: 1.45;
        white-space: pre-wrap;
        word-break: break-word;
    }
</style>

<section class="card">
    <h1>Monitor de Pedidos</h1>
    <p>Visão gerencial do período e timeline por pedido. Ordem de consulta: <strong>eventos Lexos (webhook)</strong> → <strong>Lexos WebAPI</strong> (token + chave configurados) → <strong>Mercado Livre</strong>. Busca por número de pedido usa Lexos/ML de forma direcionada quando possível.</p>
    <p><strong>Webhook Lexos (URL completa):</strong> <code><?= htmlspecialchars($webhookUrl) ?></code></p>
    <p>
        Eventos Lexos armazenados: <strong><?= htmlspecialchars((string) $storedEvents) ?></strong>.
        Entregas recebidas no webhook: <strong><?= htmlspecialchars((string) ($deliveryStats['deliveries'] ?? 0)) ?></strong>.
        <?php if (!empty($deliveryStats['last_received_at'])): ?>
            Última entrega: <strong><?= htmlspecialchars((string) $deliveryStats['last_received_at']) ?></strong>.
        <?php else: ?>
            Ainda não recebemos POST da Lexos neste endpoint.
        <?php endif; ?>
    </p>
    <?php if (!$lexosApiReady): ?>
        <p class="msg" style="margin-top:10px;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;padding:10px 12px;border-radius:8px;">
            Sem <strong>Token Lexos</strong> e <strong>Chave</strong> da integração em Configurar API, o monitor não consulta a Expedição na Lexos e cai direto no Mercado Livre quando o webhook está vazio.
        </p>
    <?php endif; ?>
    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="get">
        <input type="hidden" name="page" value="monitor-pedidos">
        <input type="hidden" name="exp_lane" value="<?= htmlspecialchars($expLane) ?>">

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
                <label for="limit">Limite de eventos exibidos</label>
                <input id="limit" type="number" name="limit" min="1" max="5000" value="<?= htmlspecialchars((string) $limit) ?>">
            </div>

            <div>
                <label>&nbsp;</label>
                <button type="submit">Atualizar Monitor</button>
            </div>
        </div>

        <div class="monitor-actions">
            <a class="btn-secondary" href="<?= htmlspecialchars($monitorUrl(['use_recent_example' => '1'])) ?>">Usar pedido recente do webhook</a>
            <?php if ($orderQuery !== '' || $selectedOrderId !== ''): ?>
                <a class="btn-secondary" href="<?= htmlspecialchars($monitorUrl(['order_query' => '', 'order_id' => ''])) ?>">Limpar timeline</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php if ($monitor && is_array($monitor['summary'] ?? null)): ?>
    <?php
    $summary = $monitor['summary'];
    $fonteAtualLabel = match ($monitorSource) {
        'mercado_livre' => 'Mercado Livre',
        'lexos_api' => 'Lexos WebAPI',
        default => 'Webhook Lexos',
    };
    if ($monitorSource === 'lexos_api') {
        if (($monitor['lexos_find_source'] ?? '') === 'entrega_datasource_todos') {
            $fonteAtualLabel = 'Lexos Expedição (busca DataSourceTodos)';
        } elseif (($monitor['lexos_list_source'] ?? '') === 'entrega_datasource_todos') {
            $fonteAtualLabel = 'Lexos Expedição (lista no período · DataSourceTodos)';
        } else {
            $fonteAtualLabel = 'Lexos WebAPI (Pedido/DataSource)';
        }
    }
    ?>
    <section class="card">
        <h1><?= $isOrderFilterActive ? 'Resumo do pedido filtrado' : 'Resumo do período' ?></h1>
        <p>Fonte atual: <strong><?= htmlspecialchars($fonteAtualLabel) ?></strong></p>
        <?php if ($isOrderFilterActive): ?>
            <p>Filtro ativo para o pedido <strong><?= htmlspecialchars($orderQuery) ?></strong>. O resumo abaixo reflete somente esse pedido.</p>
        <?php endif; ?>
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
        <p class="exp-tabs__hint">
            Abas no estilo <strong>Expedição Lexos</strong>: o portal agrupa o <strong>texto de status</strong> (API, webhook ou ML) em etapas.
            Se o rótulo da Lexos for diferente do esperado, ajuste os critérios em <code>app/Services/LexosExpeditionLane.php</code> ou use o JSON da linha para mapear campos adicionais (ex.: <em>Status envio</em> vs. aba principal).
        </p>
        <div class="exp-tabs-wrap">
            <nav class="exp-tabs" aria-label="Filtro por etapa de expedição">
                <?php foreach (LexosExpeditionLane::laneOrder() as $laneId): ?>
                    <?php
                    $count = (int) ($laneCounts[$laneId] ?? 0);
                    $isActive = $expLane === $laneId;
                    $tabUrl = $monitorUrl(['exp_lane' => $laneId]);
                    $tabLabel = $expLaneLabels[$laneId] ?? $laneId;
                    ?>
                    <a
                        class="exp-tabs__link<?= $isActive ? ' exp-tabs__link--active' : '' ?>"
                        href="<?= htmlspecialchars($tabUrl) ?>"
                        <?= $isActive ? 'aria-current="page"' : '' ?>
                    ><?= htmlspecialchars($tabLabel) ?> <span class="exp-tabs__count">(<?= htmlspecialchars((string) $count) ?>)</span></a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php if ($expLane !== LexosExpeditionLane::LANE_TODOS): ?>
            <p style="margin:0 0 12px 0;font-size:.9rem;">Exibindo <strong><?= htmlspecialchars((string) count($orders)) ?></strong> pedido(s) na etapa <strong><?= htmlspecialchars($expLaneLabels[$expLane] ?? $expLane) ?></strong>.
                <a href="<?= htmlspecialchars($monitorUrl(['exp_lane' => LexosExpeditionLane::LANE_TODOS])) ?>">Ver todos</a>
            </p>
        <?php endif; ?>
        <div style="overflow-x:auto;">
            <table style="min-width: 1100px;">
                <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Etapa (expedição)</th>
                    <th>Categoria</th>
                    <th>Status atual</th>
                    <th>Última data</th>
                    <th>Eventos</th>
                    <th>Ação recomendada</th>
                    <th>JSON</th>
                    <th>Timeline</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$orders): ?>
                    <tr><td colspan="9">Nenhum pedido encontrado para o período selecionado.</td></tr>
                <?php endif; ?>
                <?php foreach ($orders as $item): ?>
                    <?php
                    $orderId = (string) ($item['order_id'] ?? '');
                    $category = (string) ($item['category'] ?? 'outros');
                    $rawStatus = (string) ($item['status'] ?? '');
                    $laneId = LexosExpeditionLane::mapLane($rawStatus);
                    $timelineEvents = is_array($monitor['timelines'][$orderId] ?? null) ? $monitor['timelines'][$orderId] : [];
                    $lastEvent = $timelineEvents[count($timelineEvents) - 1] ?? null;
                    $jsonRow = is_array($lastEvent['row'] ?? null) ? $lastEvent['row'] : [];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($orderId) ?></td>
                        <td><?= htmlspecialchars($expLaneLabels[$laneId] ?? $laneId) ?></td>
                        <td><span class="status-pill status-pill--<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($categoryLabel($category)) ?></span></td>
                        <td><?= htmlspecialchars((string) ($item['status'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['date'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['events_count'] ?? 0)) ?></td>
                        <td><?= htmlspecialchars((string) ($item['action'] ?? '')) ?></td>
                        <td><?= $renderJsonDetails($jsonRow) ?></td>
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
                            <?php $eventRow = is_array($event['row'] ?? null) ? $event['row'] : []; ?>
                            <?php if ($eventRow !== []): ?>
                                <div style="margin-top:8px;"><?= $renderJsonDetails($eventRow, 'Ver JSON do evento') ?></div>
                            <?php endif; ?>
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
