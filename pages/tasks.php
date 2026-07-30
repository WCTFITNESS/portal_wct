<?php

declare(strict_types=1);

use App\Services\TaskService;

/** @var TaskService $taskService */
$taskService = $app['taskService'];

$feedback = null;
$feedbackClass = 'ok';
$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$showNew = isset($_GET['new']) && $_GET['new'] === '1';
$layout = trim((string) ($_GET['layout'] ?? 'board'));
if (!in_array($layout, ['board', 'list'], true)) {
    $layout = 'board';
}

$filters = [
    'status' => trim((string) ($_GET['status'] ?? 'todos')),
    'sector' => trim((string) ($_GET['sector'] ?? 'todos')),
    'q' => trim((string) ($_GET['q'] ?? '')),
];

if (isset($_GET['ok'])) {
    $feedback = (string) $_GET['ok'];
    $feedbackClass = 'ok';
}
if (isset($_GET['flash_err'])) {
    $feedback = (string) $_GET['flash_err'];
    $feedbackClass = 'err';
}

$sectors = $taskService->sectorsForSelect();
$viewTask = null;
$viewEvents = [];

if ($viewId > 0) {
    $viewTask = $taskService->get($viewId);
    if ($viewTask === null) {
        $feedback = 'Task #' . $viewId . ' não encontrada.';
        $feedbackClass = 'err';
        $viewId = 0;
    } else {
        $viewEvents = $taskService->events($viewId);
    }
}

$listFilters = $filters;
if ($filters['status'] === 'abertas') {
    $listFilters['status'] = 'todos';
}
$tasks = ($viewId > 0 || $showNew) ? [] : $taskService->list($listFilters);
if ($filters['status'] === 'abertas' && !$showNew && $viewId <= 0) {
    $tasks = array_values(array_filter(
        $tasks,
        static fn (array $t): bool => !in_array((string) $t['status'], ['concluida', 'cancelada'], true)
    ));
}

$statusLabels = TaskService::STATUSES;
$priorityLabels = TaskService::PRIORITIES;

$boardColumns = [
    'aberta' => 'Aberta',
    'em_andamento' => 'Em andamento',
    'aguardando' => 'Aguardando',
    'concluida' => 'Concluída',
    'cancelada' => 'Cancelada',
];

$tasksByStatus = [];
foreach (array_keys($boardColumns) as $statusKey) {
    $tasksByStatus[$statusKey] = [];
}
foreach ($tasks as $taskRow) {
    $stKey = (string) ($taskRow['status'] ?? 'aberta');
    if (!isset($tasksByStatus[$stKey])) {
        $tasksByStatus[$stKey] = [];
    }
    $tasksByStatus[$stKey][] = $taskRow;
}

$statusBadgeClass = static function (string $status): string {
    return match ($status) {
        'aberta' => 'task-badge-open',
        'em_andamento' => 'task-badge-progress',
        'aguardando' => 'task-badge-wait',
        'concluida' => 'task-badge-done',
        'cancelada' => 'task-badge-cancel',
        default => 'task-badge-open',
    };
};

$priorityBadgeClass = static function (string $priority): string {
    return match ($priority) {
        'urgente' => 'task-prio-urgente',
        'alta' => 'task-prio-alta',
        'baixa' => 'task-prio-baixa',
        default => 'task-prio-normal',
    };
};

$formDefaults = [
    'subject' => '',
    'description' => '',
    'sector' => '',
    'requester_name' => '',
    'requester_email' => '',
    'priority' => 'normal',
    'actor_name' => '',
];

$tasksBase = portal_wct_public_path($baseUrl, 'index.php?page=tasks');
$moveApiUrl = portal_wct_public_path($baseUrl, 'index.php?page=tasks&tasks_api=move');

$queryKeep = static function (array $extra) use ($filters, $layout): string {
    $params = array_merge([
        'page' => 'tasks',
        'layout' => $layout,
        'status' => $filters['status'],
        'sector' => $filters['sector'],
        'q' => $filters['q'],
    ], $extra);
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            unset($params[$k]);
        }
    }

    return http_build_query($params);
};

?>
<style>
    .tasks-page h1 { margin-bottom: 6px; }
    .tasks-lead { color: #64748b; margin: 0 0 14px; }
    .tasks-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: end;
        margin-bottom: 16px;
    }
    .tasks-toolbar .grow { flex: 1 1 160px; }
    .tasks-toolbar label { margin-top: 0; font-size: .85rem; }
    .tasks-toolbar input, .tasks-toolbar select { margin-top: 4px; }
    .tasks-toolbar button {
        margin-top: 0;
        height: 40px;
    }
    .tasks-view-toggle {
        display: inline-flex;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        overflow: hidden;
        margin-right: 8px;
    }
    .tasks-view-toggle a {
        padding: 8px 12px;
        text-decoration: none;
        color: #334155;
        font-weight: 600;
        font-size: .85rem;
        background: #fff;
    }
    .tasks-view-toggle a.active {
        background: #111;
        color: #f5b700;
    }
    .tasks-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    .tasks-actions .btn-new {
        padding: 10px 14px;
        border: 1px solid #111;
        border-radius: 6px;
        background: #111;
        color: #f5b700;
        text-decoration: none;
        font-weight: bold;
        letter-spacing: .04em;
        text-transform: uppercase;
        font-size: .78rem;
    }
    .tasks-actions .btn-new:hover { background: #f5b700; color: #111; }
    .tasks-actions .btn-back {
        padding: 8px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        text-decoration: none;
        color: #334155;
        font-weight: 600;
        background: #fff;
    }
    .task-badge {
        display: inline-block;
        padding: 3px 9px;
        border-radius: 999px;
        font-size: .72rem;
        font-weight: 700;
        color: #fff;
        white-space: nowrap;
    }
    .task-badge-open { background: #2563eb; }
    .task-badge-progress { background: #d97706; }
    .task-badge-wait { background: #7c3aed; }
    .task-badge-done { background: #16a34a; }
    .task-badge-cancel { background: #64748b; }
    .task-prio {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: .72rem;
        font-weight: 600;
    }
    .task-prio-normal { background: #e2e8f0; color: #334155; }
    .task-prio-baixa { background: #f1f5f9; color: #64748b; }
    .task-prio-alta { background: #ffedd5; color: #c2410c; }
    .task-prio-urgente { background: #fee2e2; color: #b91c1c; }
    .tasks-table a { font-weight: 600; text-decoration: none; }
    .tasks-table a:hover { text-decoration: underline; }
    .tasks-meta { color: #64748b; font-size: .8rem; }
    .task-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px 16px;
    }
    .task-grid .full { grid-column: 1 / -1; }
    .task-detail-header {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }
    .task-detail-header h1 { margin: 0; }
    .task-panels {
        display: grid;
        grid-template-columns: 1.4fr 1fr;
        gap: 16px;
        margin-top: 16px;
    }
    .task-panel {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 14px 16px;
    }
    .task-panel h2 { margin: 0 0 10px; font-size: 1rem; }
    .task-timeline {
        list-style: none;
        margin: 0;
        padding: 0;
        max-height: 420px;
        overflow: auto;
    }
    .task-timeline li {
        border-left: 3px solid #f5b700;
        padding: 0 0 14px 12px;
        margin-left: 4px;
    }
    .task-timeline .when { font-size: .75rem; color: #64748b; margin-bottom: 2px; }
    .task-timeline .type {
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #92400e;
        margin-right: 6px;
    }
    .task-timeline .mail-ok { color: #16a34a; font-size: .72rem; }
    .task-timeline .mail-err { color: #dc2626; font-size: .72rem; }
    .task-desc {
        white-space: pre-wrap;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 12px;
        margin: 8px 0 0;
    }

    /* Kanban */
    .kanban-board {
        display: flex;
        gap: 12px;
        overflow-x: auto;
        padding-bottom: 8px;
        min-height: 520px;
        align-items: flex-start;
    }
    .kanban-col {
        flex: 0 0 280px;
        width: 280px;
        background: #eef1f5;
        border-radius: 10px;
        border: 1px solid #dde3ec;
        display: flex;
        flex-direction: column;
        max-height: calc(100vh - 220px);
    }
    .kanban-col-header {
        padding: 12px 12px 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        border-bottom: 1px solid #dde3ec;
        position: sticky;
        top: 0;
        background: #eef1f5;
        border-radius: 10px 10px 0 0;
        z-index: 1;
    }
    .kanban-col-header h3 {
        margin: 0;
        font-size: .92rem;
        color: #1f2937;
    }
    .kanban-count {
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        min-width: 24px;
        height: 24px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: .75rem;
        font-weight: 700;
        color: #475569;
        padding: 0 7px;
    }
    .kanban-col[data-status="aberta"] .kanban-col-header { box-shadow: inset 0 3px 0 #2563eb; }
    .kanban-col[data-status="em_andamento"] .kanban-col-header { box-shadow: inset 0 3px 0 #d97706; }
    .kanban-col[data-status="aguardando"] .kanban-col-header { box-shadow: inset 0 3px 0 #7c3aed; }
    .kanban-col[data-status="concluida"] .kanban-col-header { box-shadow: inset 0 3px 0 #16a34a; }
    .kanban-col[data-status="cancelada"] .kanban-col-header { box-shadow: inset 0 3px 0 #64748b; }
    .kanban-cards {
        padding: 10px;
        overflow-y: auto;
        flex: 1;
        min-height: 120px;
    }
    .kanban-col.drag-over {
        outline: 2px dashed #f5b700;
        outline-offset: -4px;
        background: #fff8e6;
    }
    .kanban-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 11px;
        margin-bottom: 8px;
        box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        cursor: grab;
        transition: box-shadow .15s ease, transform .15s ease;
    }
    .kanban-card:active { cursor: grabbing; }
    .kanban-card.dragging {
        opacity: .55;
        transform: rotate(1.5deg);
    }
    .kanban-card:hover {
        box-shadow: 0 4px 12px rgba(15, 23, 42, .1);
    }
    .kanban-card-title {
        font-weight: 700;
        font-size: .9rem;
        color: #111;
        text-decoration: none;
        display: block;
        margin-bottom: 6px;
        line-height: 1.3;
    }
    .kanban-card-title:hover { color: #d50000; }
    .kanban-card-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 4px 6px;
        align-items: center;
        margin-bottom: 6px;
    }
    .kanban-card-foot {
        font-size: .75rem;
        color: #64748b;
        display: flex;
        justify-content: space-between;
        gap: 8px;
    }
    .kanban-empty {
        color: #94a3b8;
        font-size: .8rem;
        text-align: center;
        padding: 18px 8px;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
    }
    .kanban-hint {
        font-size: .8rem;
        color: #64748b;
        margin: 0 0 12px;
    }
    #kanban-toast {
        position: fixed;
        right: 18px;
        bottom: 18px;
        z-index: 50;
        background: #111;
        color: #f5b700;
        padding: 10px 14px;
        border-radius: 8px;
        font-size: .85rem;
        font-weight: 600;
        display: none;
        box-shadow: 0 8px 24px rgba(0,0,0,.2);
        max-width: 360px;
    }
    #kanban-toast.err { color: #fecaca; }
    @media (max-width: 900px) {
        .task-grid, .task-panels { grid-template-columns: 1fr; }
        .kanban-col { flex-basis: 250px; width: 250px; }
    }
</style>

<section class="card tasks-page">
    <?php if ($feedback): ?>
        <div class="msg <?= htmlspecialchars($feedbackClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($showNew): ?>
        <div class="task-detail-header">
            <h1>Nova task</h1>
            <a class="btn-back" href="<?= htmlspecialchars($tasksBase, ENT_QUOTES, 'UTF-8') ?>">← Voltar ao quadro</a>
        </div>
        <p class="tasks-lead">Registre uma atividade/chamado. O solicitante recebe e-mail a cada atualização.</p>

        <form method="post" class="task-grid" action="<?= htmlspecialchars($tasksBase, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="form_type" value="task_create">
            <label class="full">Assunto *
                <input type="text" name="subject" required maxlength="200" value="<?= htmlspecialchars($formDefaults['subject'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label class="full">Descrição *
                <textarea name="description" rows="5" required><?= htmlspecialchars($formDefaults['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </label>
            <label>Setor *
                <select name="sector" required>
                    <option value="">Selecione…</option>
                    <?php foreach ($sectors as $sector): ?>
                        <option value="<?= htmlspecialchars($sector, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sector, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Prioridade
                <select name="priority">
                    <?php foreach ($priorityLabels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $formDefaults['priority'] === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Solicitante *
                <input type="text" name="requester_name" required maxlength="120">
            </label>
            <label>E-mail do solicitante *
                <input type="email" name="requester_email" required maxlength="200">
            </label>
            <label class="full">Quem está registrando (opcional)
                <input type="text" name="actor_name" maxlength="120" placeholder="Seu nome">
            </label>
            <div class="full">
                <button type="submit">Criar task</button>
            </div>
        </form>

    <?php elseif ($viewTask !== null): ?>
        <?php
        $st = (string) $viewTask['status'];
        $pr = (string) $viewTask['priority'];
        ?>
        <div class="task-detail-header">
            <div>
                <h1>#<?= (int) $viewTask['id'] ?> — <?= htmlspecialchars((string) $viewTask['subject'], ENT_QUOTES, 'UTF-8') ?></h1>
                <div class="tasks-meta" style="margin-top:6px;">
                    <span class="task-badge <?= $statusBadgeClass($st) ?>"><?= htmlspecialchars($statusLabels[$st] ?? $st, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="task-prio <?= $priorityBadgeClass($pr) ?>"><?= htmlspecialchars($priorityLabels[$pr] ?? $pr, ENT_QUOTES, 'UTF-8') ?></span>
                    · Setor: <?= htmlspecialchars((string) $viewTask['sector'], ENT_QUOTES, 'UTF-8') ?>
                    · Criada: <?= htmlspecialchars((string) $viewTask['created_at'], ENT_QUOTES, 'UTF-8') ?>
                    · Atualizada: <?= htmlspecialchars((string) $viewTask['updated_at'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <a class="btn-back" href="<?= htmlspecialchars($tasksBase, ENT_QUOTES, 'UTF-8') ?>">← Quadro</a>
        </div>

        <p class="tasks-lead" style="margin-bottom:8px;">
            Solicitante: <strong><?= htmlspecialchars((string) $viewTask['requester_name'], ENT_QUOTES, 'UTF-8') ?></strong>
            &lt;<?= htmlspecialchars((string) $viewTask['requester_email'], ENT_QUOTES, 'UTF-8') ?>&gt;
        </p>
        <div class="task-desc"><?= htmlspecialchars((string) $viewTask['description'], ENT_QUOTES, 'UTF-8') ?></div>

        <div class="task-panels">
            <div>
                <div class="task-panel" style="margin-bottom:16px;">
                    <h2>Alterar status</h2>
                    <form method="post" action="<?= htmlspecialchars($tasksBase, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_type" value="task_status">
                        <input type="hidden" name="task_id" value="<?= (int) $viewTask['id'] ?>">
                        <label>Novo status
                            <select name="status">
                                <?php foreach ($statusLabels as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $st === $key ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Observação (opcional)
                            <input type="text" name="note" maxlength="500" placeholder="Ex.: aguardando retorno do fornecedor">
                        </label>
                        <label>Seu nome
                            <input type="text" name="actor_name" maxlength="120" placeholder="Quem está atualizando">
                        </label>
                        <button type="submit">Salvar status</button>
                    </form>
                </div>

                <div class="task-panel" style="margin-bottom:16px;">
                    <h2>Adicionar comentário</h2>
                    <form method="post" action="<?= htmlspecialchars($tasksBase, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_type" value="task_comment">
                        <input type="hidden" name="task_id" value="<?= (int) $viewTask['id'] ?>">
                        <label>Comentário *
                            <textarea name="comment" rows="4" required placeholder="O que foi feito / o que falta…"></textarea>
                        </label>
                        <label>Seu nome
                            <input type="text" name="actor_name" maxlength="120">
                        </label>
                        <button type="submit">Registrar comentário</button>
                    </form>
                </div>

                <div class="task-panel">
                    <h2>Editar dados</h2>
                    <form method="post" class="task-grid" action="<?= htmlspecialchars($tasksBase, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_type" value="task_update">
                        <input type="hidden" name="task_id" value="<?= (int) $viewTask['id'] ?>">
                        <label class="full">Assunto *
                            <input type="text" name="subject" required maxlength="200" value="<?= htmlspecialchars((string) $viewTask['subject'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="full">Descrição *
                            <textarea name="description" rows="4" required><?= htmlspecialchars((string) $viewTask['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </label>
                        <label>Setor *
                            <select name="sector" required>
                                <?php foreach ($sectors as $sector): ?>
                                    <option value="<?= htmlspecialchars($sector, ENT_QUOTES, 'UTF-8') ?>" <?= (string) $viewTask['sector'] === $sector ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sector, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Prioridade
                            <select name="priority">
                                <?php foreach ($priorityLabels as $key => $label): ?>
                                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $pr === $key ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Solicitante *
                            <input type="text" name="requester_name" required maxlength="120" value="<?= htmlspecialchars((string) $viewTask['requester_name'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label>E-mail *
                            <input type="email" name="requester_email" required maxlength="200" value="<?= htmlspecialchars((string) $viewTask['requester_email'], ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="full">Seu nome
                            <input type="text" name="actor_name" maxlength="120">
                        </label>
                        <div class="full">
                            <button type="submit">Salvar alterações</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="task-panel">
                <h2>Histórico</h2>
                <?php if ($viewEvents === []): ?>
                    <p class="tasks-meta">Nenhum evento ainda.</p>
                <?php else: ?>
                    <ul class="task-timeline">
                        <?php foreach ($viewEvents as $ev): ?>
                            <?php
                            $etype = (string) ($ev['event_type'] ?? '');
                            $etypeLabel = match ($etype) {
                                'created' => 'Criação',
                                'status_changed' => 'Status',
                                'comment' => 'Comentário',
                                'updated' => 'Edição',
                                default => $etype,
                            };
                            $emailSent = !empty($ev['email_sent']) && $ev['email_sent'] !== 'f' && $ev['email_sent'] !== 'false';
                            ?>
                            <li>
                                <div class="when">
                                    <span class="type"><?= htmlspecialchars($etypeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?= htmlspecialchars((string) ($ev['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($ev['actor_name'])): ?>
                                        · <?= htmlspecialchars((string) $ev['actor_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                    <?php if ($emailSent): ?>
                                        <span class="mail-ok">· e-mail ok</span>
                                    <?php elseif (!empty($ev['email_error'])): ?>
                                        <span class="mail-err" title="<?= htmlspecialchars((string) $ev['email_error'], ENT_QUOTES, 'UTF-8') ?>">· e-mail falhou</span>
                                    <?php endif; ?>
                                </div>
                                <div><?= nl2br(htmlspecialchars((string) ($ev['message'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
        <div class="task-detail-header">
            <div>
                <h1>Tasks</h1>
                <p class="tasks-lead">Quadro de atividades. Arraste o card entre colunas para mudar o status (notifica o solicitante).</p>
            </div>
            <div class="tasks-actions">
                <div class="tasks-view-toggle">
                    <a class="<?= $layout === 'board' ? 'active' : '' ?>" href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?' . $queryKeep(['layout' => 'board'])), ENT_QUOTES, 'UTF-8') ?>">Quadro</a>
                    <a class="<?= $layout === 'list' ? 'active' : '' ?>" href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?' . $queryKeep(['layout' => 'list'])), ENT_QUOTES, 'UTF-8') ?>">Lista</a>
                </div>
                <a class="btn-new" href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=tasks&new=1'), ENT_QUOTES, 'UTF-8') ?>">+ Nova task</a>
            </div>
        </div>

        <form method="get" class="tasks-toolbar">
            <input type="hidden" name="page" value="tasks">
            <input type="hidden" name="layout" value="<?= htmlspecialchars($layout, ENT_QUOTES, 'UTF-8') ?>">
            <label class="grow">Buscar
                <input type="text" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Assunto, descrição, solicitante…">
            </label>
            <?php if ($layout === 'list'): ?>
                <label>Status
                    <select name="status">
                        <option value="todos" <?= $filters['status'] === 'todos' ? 'selected' : '' ?>>Todos</option>
                        <option value="abertas" <?= $filters['status'] === 'abertas' ? 'selected' : '' ?>>Em aberto</option>
                        <?php foreach ($statusLabels as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php else: ?>
                <input type="hidden" name="status" value="todos">
            <?php endif; ?>
            <label>Setor
                <select name="sector">
                    <option value="todos" <?= $filters['sector'] === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <?php foreach ($sectors as $sector): ?>
                        <option value="<?= htmlspecialchars($sector, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['sector'] === $sector ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sector, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Filtrar</button>
        </form>

        <?php if ($layout === 'board'): ?>
            <p class="kanban-hint">Arraste um card para outra coluna. Clique no título para abrir o detalhe.</p>
            <div class="kanban-board" id="kanban-board" data-move-url="<?= htmlspecialchars($moveApiUrl, ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($boardColumns as $colStatus => $colLabel): ?>
                    <?php $colTasks = $tasksByStatus[$colStatus] ?? []; ?>
                    <div class="kanban-col" data-status="<?= htmlspecialchars($colStatus, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="kanban-col-header">
                            <h3><?= htmlspecialchars($colLabel, ENT_QUOTES, 'UTF-8') ?></h3>
                            <span class="kanban-count"><?= count($colTasks) ?></span>
                        </div>
                        <div class="kanban-cards" data-dropzone="1">
                            <?php if ($colTasks === []): ?>
                                <div class="kanban-empty">Solte cards aqui</div>
                            <?php endif; ?>
                            <?php foreach ($colTasks as $t): ?>
                                <?php
                                $tid = (int) $t['id'];
                                $tpr = (string) $t['priority'];
                                $href = portal_wct_public_path($baseUrl, 'index.php?page=tasks&id=' . $tid);
                                $snippet = mb_substr(trim((string) $t['description']), 0, 90);
                                if (mb_strlen(trim((string) $t['description'])) > 90) {
                                    $snippet .= '…';
                                }
                                ?>
                                <article
                                    class="kanban-card"
                                    draggable="true"
                                    data-task-id="<?= $tid ?>"
                                    data-status="<?= htmlspecialchars((string) $t['status'], ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    <a class="kanban-card-title" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                                        #<?= $tid ?> <?= htmlspecialchars((string) $t['subject'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                    <div class="kanban-card-meta">
                                        <span class="task-prio <?= $priorityBadgeClass($tpr) ?>"><?= htmlspecialchars($priorityLabels[$tpr] ?? $tpr, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="tasks-meta"><?= htmlspecialchars((string) $t['sector'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <?php if ($snippet !== ''): ?>
                                        <div class="tasks-meta" style="margin-bottom:6px;"><?= htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <div class="kanban-card-foot">
                                        <span><?= htmlspecialchars((string) $t['requester_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span><?= htmlspecialchars(substr((string) $t['updated_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="kanban-toast"></div>
            <script>
            (function () {
                var board = document.getElementById('kanban-board');
                if (!board) return;
                var moveUrl = board.getAttribute('data-move-url');
                var toast = document.getElementById('kanban-toast');
                var dragged = null;

                function showToast(msg, isErr) {
                    if (!toast) return;
                    toast.textContent = msg;
                    toast.className = isErr ? 'err' : '';
                    toast.style.display = 'block';
                    clearTimeout(showToast._t);
                    showToast._t = setTimeout(function () { toast.style.display = 'none'; }, 3200);
                }

                function refreshCounts() {
                    board.querySelectorAll('.kanban-col').forEach(function (col) {
                        var cards = col.querySelectorAll('.kanban-card').length;
                        var countEl = col.querySelector('.kanban-count');
                        if (countEl) countEl.textContent = String(cards);
                        var zone = col.querySelector('.kanban-cards');
                        if (!zone) return;
                        var empty = zone.querySelector('.kanban-empty');
                        if (cards === 0 && !empty) {
                            var d = document.createElement('div');
                            d.className = 'kanban-empty';
                            d.textContent = 'Solte cards aqui';
                            zone.appendChild(d);
                        } else if (cards > 0 && empty) {
                            empty.remove();
                        }
                    });
                }

                board.querySelectorAll('.kanban-card').forEach(function (card) {
                    card.addEventListener('dragstart', function (e) {
                        dragged = card;
                        card.classList.add('dragging');
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', card.getAttribute('data-task-id') || '');
                    });
                    card.addEventListener('dragend', function () {
                        card.classList.remove('dragging');
                        board.querySelectorAll('.kanban-col').forEach(function (c) { c.classList.remove('drag-over'); });
                        dragged = null;
                    });
                });

                board.querySelectorAll('.kanban-col').forEach(function (col) {
                    col.addEventListener('dragover', function (e) {
                        e.preventDefault();
                        col.classList.add('drag-over');
                        e.dataTransfer.dropEffect = 'move';
                    });
                    col.addEventListener('dragleave', function (e) {
                        if (!col.contains(e.relatedTarget)) {
                            col.classList.remove('drag-over');
                        }
                    });
                    col.addEventListener('drop', function (e) {
                        e.preventDefault();
                        col.classList.remove('drag-over');
                        if (!dragged) return;
                        var newStatus = col.getAttribute('data-status');
                        var oldStatus = dragged.getAttribute('data-status');
                        if (!newStatus || newStatus === oldStatus) return;

                        var zone = col.querySelector('.kanban-cards');
                        if (!zone) return;
                        zone.appendChild(dragged);
                        dragged.setAttribute('data-status', newStatus);
                        refreshCounts();

                        var taskId = dragged.getAttribute('data-task-id');
                        var body = new URLSearchParams();
                        body.set('form_type', 'task_move');
                        body.set('tasks_api', 'move');
                        body.set('task_id', taskId);
                        body.set('status', newStatus);
                        body.set('actor_name', 'Quadro Kanban');

                        fetch(moveUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                            body: body.toString(),
                            credentials: 'same-origin'
                        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                          .then(function (res) {
                              if (!res.ok || !res.j.ok) {
                                  throw new Error((res.j && res.j.error) || 'Falha ao mover task');
                              }
                              var msg = 'Task #' + taskId + ' movida.';
                              if (res.j.email_ok === false) {
                                  msg += ' E-mail não enviado.';
                              }
                              showToast(msg, false);
                          })
                          .catch(function (err) {
                              showToast(err.message || 'Erro ao salvar', true);
                              // reload to restore truth from server
                              setTimeout(function () { location.reload(); }, 1200);
                          });
                    });
                });
            })();
            </script>

        <?php else: ?>
            <?php if ($tasks === []): ?>
                <p class="tasks-meta">Nenhuma task encontrada. <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=tasks&new=1'), ENT_QUOTES, 'UTF-8') ?>">Criar a primeira</a>.</p>
            <?php else: ?>
                <div class="table-wrap" style="overflow-x:auto;">
                    <table class="tasks-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Assunto</th>
                                <th>Setor</th>
                                <th>Solicitante</th>
                                <th>Prioridade</th>
                                <th>Status</th>
                                <th>Atualizada</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $t): ?>
                                <?php
                                $tid = (int) $t['id'];
                                $tst = (string) $t['status'];
                                $tpr = (string) $t['priority'];
                                $href = portal_wct_public_path($baseUrl, 'index.php?page=tasks&id=' . $tid);
                                ?>
                                <tr>
                                    <td><?= $tid ?></td>
                                    <td><a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $t['subject'], ENT_QUOTES, 'UTF-8') ?></a></td>
                                    <td><?= htmlspecialchars((string) $t['sector'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?= htmlspecialchars((string) $t['requester_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <div class="tasks-meta"><?= htmlspecialchars((string) $t['requester_email'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><span class="task-prio <?= $priorityBadgeClass($tpr) ?>"><?= htmlspecialchars($priorityLabels[$tpr] ?? $tpr, ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="task-badge <?= $statusBadgeClass($tst) ?>"><?= htmlspecialchars($statusLabels[$tst] ?? $tst, ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td class="tasks-meta"><?= htmlspecialchars((string) $t['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
