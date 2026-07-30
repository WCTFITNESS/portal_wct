<?php

declare(strict_types=1);

use App\Services\TaskService;

/** @var TaskService $taskService */
$taskService = $app['taskService'];

$feedback = null;
$feedbackClass = 'ok';
$viewId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$showNew = isset($_GET['new']) && $_GET['new'] === '1';

$filters = [
    'status' => trim((string) ($_GET['status'] ?? 'abertas')),
    'sector' => trim((string) ($_GET['sector'] ?? 'todos')),
    'q' => trim((string) ($_GET['q'] ?? '')),
];

if ($filters['status'] === 'abertas') {
    // lista padrão: tudo que não está concluída/cancelada — filtrado depois
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formType = (string) ($_POST['form_type'] ?? '');
    try {
        if ($formType === 'task_create') {
            $result = $taskService->create([
                'subject' => (string) ($_POST['subject'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'sector' => (string) ($_POST['sector'] ?? ''),
                'requester_name' => (string) ($_POST['requester_name'] ?? ''),
                'requester_email' => (string) ($_POST['requester_email'] ?? ''),
                'priority' => (string) ($_POST['priority'] ?? 'normal'),
                'actor_name' => (string) ($_POST['actor_name'] ?? ($_POST['requester_name'] ?? '')),
            ]);
            $newId = (int) $result['task']['id'];
            $msg = 'Task #' . $newId . ' criada.';
            if (!$result['email_ok']) {
                $msg .= ' Aviso: e-mail não enviado (' . ($result['email_error'] ?? 'erro') . ').';
            } else {
                $msg .= ' E-mail enviado ao solicitante.';
            }
            redirect_to('index.php?page=tasks&id=' . $newId . '&ok=' . rawurlencode($msg));
        }

        if ($formType === 'task_status') {
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $result = $taskService->changeStatus(
                $taskId,
                (string) ($_POST['status'] ?? ''),
                trim((string) ($_POST['actor_name'] ?? '')) ?: null,
                trim((string) ($_POST['note'] ?? '')) ?: null
            );
            $msg = 'Status atualizado.';
            if (!$result['email_ok']) {
                $msg .= ' Aviso: e-mail não enviado (' . ($result['email_error'] ?? 'erro') . ').';
            }
            redirect_to('index.php?page=tasks&id=' . $taskId . '&ok=' . rawurlencode($msg));
        }

        if ($formType === 'task_comment') {
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $result = $taskService->addComment(
                $taskId,
                (string) ($_POST['comment'] ?? ''),
                trim((string) ($_POST['actor_name'] ?? '')) ?: null
            );
            $msg = 'Comentário registrado.';
            if (!$result['email_ok']) {
                $msg .= ' Aviso: e-mail não enviado (' . ($result['email_error'] ?? 'erro') . ').';
            }
            redirect_to('index.php?page=tasks&id=' . $taskId . '&ok=' . rawurlencode($msg));
        }

        if ($formType === 'task_update') {
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $result = $taskService->updateDetails($taskId, [
                'subject' => (string) ($_POST['subject'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'sector' => (string) ($_POST['sector'] ?? ''),
                'requester_name' => (string) ($_POST['requester_name'] ?? ''),
                'requester_email' => (string) ($_POST['requester_email'] ?? ''),
                'priority' => (string) ($_POST['priority'] ?? 'normal'),
                'actor_name' => (string) ($_POST['actor_name'] ?? ''),
            ]);
            $msg = 'Task atualizada.';
            if (!$result['email_ok']) {
                $msg .= ' Aviso: e-mail não enviado (' . ($result['email_error'] ?? 'erro') . ').';
            }
            redirect_to('index.php?page=tasks&id=' . $taskId . '&ok=' . rawurlencode($msg));
        }
    } catch (Throwable $e) {
        $feedback = $e->getMessage();
        $feedbackClass = 'err';
        if (in_array($formType, ['task_status', 'task_comment', 'task_update'], true)) {
            $viewId = (int) ($_POST['task_id'] ?? $viewId);
        } else {
            $showNew = true;
        }
    }
}

if (isset($_GET['ok']) && $feedback === null) {
    $feedback = (string) $_GET['ok'];
    $feedbackClass = 'ok';
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
$tasks = $viewId > 0 || $showNew ? [] : $taskService->list($listFilters);
if ($filters['status'] === 'abertas' && !$showNew && $viewId <= 0) {
    $tasks = array_values(array_filter(
        $tasks,
        static fn (array $t): bool => !in_array((string) $t['status'], ['concluida', 'cancelada'], true)
    ));
}

$statusLabels = TaskService::STATUSES;
$priorityLabels = TaskService::PRIORITIES;

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
    'subject' => trim((string) ($_POST['subject'] ?? '')),
    'description' => trim((string) ($_POST['description'] ?? '')),
    'sector' => trim((string) ($_POST['sector'] ?? '')),
    'requester_name' => trim((string) ($_POST['requester_name'] ?? '')),
    'requester_email' => trim((string) ($_POST['requester_email'] ?? '')),
    'priority' => trim((string) ($_POST['priority'] ?? 'normal')),
    'actor_name' => trim((string) ($_POST['actor_name'] ?? '')),
];

?>
<style>
    .tasks-page h1 { margin-bottom: 6px; }
    .tasks-lead { color: #64748b; margin: 0 0 18px; }
    .tasks-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: end;
        margin-bottom: 16px;
    }
    .tasks-toolbar .grow { flex: 1 1 180px; }
    .tasks-toolbar label { margin-top: 0; font-size: .85rem; }
    .tasks-toolbar input, .tasks-toolbar select { margin-top: 4px; }
    .tasks-toolbar button, .tasks-toolbar .btn-link {
        margin-top: 0;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        box-sizing: border-box;
        padding: 0 14px;
        border-radius: 6px;
        font-weight: bold;
        letter-spacing: .04em;
        text-transform: uppercase;
        font-size: .78rem;
    }
    .tasks-toolbar .btn-link {
        border: 1px solid #111;
        background: #111;
        color: #f5b700;
    }
    .tasks-toolbar .btn-link:hover { background: #f5b700; color: #111; }
    .tasks-toolbar .btn-secondary {
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
        text-transform: none;
        letter-spacing: 0;
        font-weight: 600;
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
    .task-panel h2 {
        margin: 0 0 10px;
        font-size: 1rem;
    }
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
    .task-timeline .when {
        font-size: .75rem;
        color: #64748b;
        margin-bottom: 2px;
    }
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
    @media (max-width: 900px) {
        .task-grid, .task-panels { grid-template-columns: 1fr; }
    }
</style>

<section class="card tasks-page">
    <?php if ($feedback): ?>
        <div class="msg <?= htmlspecialchars($feedbackClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($showNew): ?>
        <div class="task-detail-header">
            <h1>Nova task</h1>
            <a class="btn-secondary" href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=tasks'), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;text-decoration:none;color:#334155;font-weight:600;">← Voltar à lista</a>
        </div>
        <p class="tasks-lead">Registre uma atividade/chamado. O solicitante recebe e-mail a cada atualização.</p>

        <form method="post" class="task-grid">
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
                        <option value="<?= htmlspecialchars($sector, ENT_QUOTES, 'UTF-8') ?>" <?= $formDefaults['sector'] === $sector ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sector, ENT_QUOTES, 'UTF-8') ?>
                        </option>
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
                <input type="text" name="requester_name" required maxlength="120" value="<?= htmlspecialchars($formDefaults['requester_name'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label>E-mail do solicitante *
                <input type="email" name="requester_email" required maxlength="200" value="<?= htmlspecialchars($formDefaults['requester_email'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label class="full">Quem está registrando (opcional)
                <input type="text" name="actor_name" maxlength="120" placeholder="Seu nome" value="<?= htmlspecialchars($formDefaults['actor_name'], ENT_QUOTES, 'UTF-8') ?>">
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
            <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=tasks'), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 12px;border:1px solid #cbd5e1;border-radius:6px;text-decoration:none;color:#334155;font-weight:600;">← Lista</a>
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
                    <form method="post">
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
                    <form method="post">
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
                    <form method="post" class="task-grid">
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
                <p class="tasks-lead">Chamados e atividades da empresa. Toda ação notifica o e-mail do solicitante.</p>
            </div>
            <a class="btn-link" href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=tasks&new=1'), ENT_QUOTES, 'UTF-8') ?>" style="padding:10px 14px;border:1px solid #111;border-radius:6px;background:#111;color:#f5b700;text-decoration:none;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;font-size:.78rem;">+ Nova task</a>
        </div>

        <form method="get" class="tasks-toolbar">
            <input type="hidden" name="page" value="tasks">
            <label class="grow">Buscar
                <input type="text" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Assunto, descrição, solicitante…">
            </label>
            <label>Status
                <select name="status">
                    <option value="abertas" <?= $filters['status'] === 'abertas' ? 'selected' : '' ?>>Em aberto</option>
                    <option value="todos" <?= $filters['status'] === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <?php foreach ($statusLabels as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
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
                                <td>
                                    <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $t['subject'], ENT_QUOTES, 'UTF-8') ?></a>
                                </td>
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
</section>
