<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TaskRepository;
use RuntimeException;

class TaskService
{
    public const STATUSES = [
        'aberta' => 'Aberta',
        'em_andamento' => 'Em andamento',
        'aguardando' => 'Aguardando',
        'concluida' => 'Concluída',
        'cancelada' => 'Cancelada',
    ];

    public const PRIORITIES = [
        'baixa' => 'Baixa',
        'normal' => 'Normal',
        'alta' => 'Alta',
        'urgente' => 'Urgente',
    ];

    public const SECTORS = [
        'TI',
        'Expedição',
        'Comercial',
        'Financeiro',
        'Operações',
        'Marketing',
        'RH',
        'Protheus',
        'Lexos',
        'Mercado Livre',
        'Outro',
    ];

    public function __construct(
        private TaskRepository $taskRepository,
        private MailService $mailService,
        private string $baseUrl = '/'
    ) {
    }

    /**
     * @param array{
     *   subject: string,
     *   description: string,
     *   sector: string,
     *   requester_name: string,
     *   requester_email: string,
     *   priority?: string,
     *   actor_name?: string
     * } $input
     * @return array{task: array<string, mixed>, email_ok: bool, email_error: string|null}
     */
    public function create(array $input): array
    {
        $subject = trim((string) ($input['subject'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $sector = trim((string) ($input['sector'] ?? ''));
        $requesterName = trim((string) ($input['requester_name'] ?? ''));
        $requesterEmail = trim((string) ($input['requester_email'] ?? ''));
        $priority = trim((string) ($input['priority'] ?? 'normal'));
        $actorName = trim((string) ($input['actor_name'] ?? $requesterName));

        if ($subject === '') {
            throw new RuntimeException('Informe o assunto.');
        }
        if ($description === '') {
            throw new RuntimeException('Informe a descrição.');
        }
        if ($sector === '') {
            throw new RuntimeException('Informe o setor.');
        }
        if ($requesterName === '') {
            throw new RuntimeException('Informe o solicitante.');
        }
        if ($requesterEmail === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido do solicitante.');
        }
        if (!isset(self::PRIORITIES[$priority])) {
            $priority = 'normal';
        }

        $id = $this->taskRepository->create([
            'subject' => mb_substr($subject, 0, 200),
            'description' => $description,
            'sector' => mb_substr($sector, 0, 80),
            'requester_name' => mb_substr($requesterName, 0, 120),
            'requester_email' => mb_substr($requesterEmail, 0, 200),
            'status' => 'aberta',
            'priority' => $priority,
        ]);

        $task = $this->taskRepository->findById($id);
        if ($task === null) {
            throw new RuntimeException('Task criada, mas não foi possível recarregar.');
        }

        $eventMessage = 'Task criada.';
        $mail = $this->notifyCreator($task, 'Task criada #' . $id . ' — ' . $subject, $this->buildCreatedBody($task));
        $this->taskRepository->addEvent(
            $id,
            'created',
            $eventMessage,
            $actorName !== '' ? $actorName : null,
            $mail['ok'],
            $mail['error']
        );

        return [
            'task' => $task,
            'email_ok' => $mail['ok'],
            'email_error' => $mail['error'],
        ];
    }

    /**
     * @return array{task: array<string, mixed>, email_ok: bool, email_error: string|null}
     */
    public function changeStatus(int $taskId, string $newStatus, ?string $actorName = null, ?string $note = null): array
    {
        $task = $this->requireTask($taskId);
        $newStatus = trim($newStatus);
        if (!isset(self::STATUSES[$newStatus])) {
            throw new RuntimeException('Status inválido.');
        }

        $oldStatus = (string) $task['status'];
        if ($oldStatus === $newStatus && ($note === null || trim($note) === '')) {
            return ['task' => $task, 'email_ok' => true, 'email_error' => null];
        }

        $this->taskRepository->updateStatus($taskId, $newStatus);
        $task = $this->requireTask($taskId);

        $labelOld = self::STATUSES[$oldStatus] ?? $oldStatus;
        $labelNew = self::STATUSES[$newStatus] ?? $newStatus;
        $message = "Status alterado de {$labelOld} para {$labelNew}.";
        if ($note !== null && trim($note) !== '') {
            $message .= ' Observação: ' . trim($note);
        }

        $mail = $this->notifyCreator(
            $task,
            'Atualização task #' . $taskId . ' — ' . $task['subject'],
            $this->buildUpdateBody($task, $message, $actorName)
        );
        $this->taskRepository->addEvent(
            $taskId,
            'status_changed',
            $message,
            $actorName,
            $mail['ok'],
            $mail['error']
        );

        return [
            'task' => $task,
            'email_ok' => $mail['ok'],
            'email_error' => $mail['error'],
        ];
    }

    /**
     * @return array{task: array<string, mixed>, email_ok: bool, email_error: string|null}
     */
    public function addComment(int $taskId, string $comment, ?string $actorName = null): array
    {
        $task = $this->requireTask($taskId);
        $comment = trim($comment);
        if ($comment === '') {
            throw new RuntimeException('Informe o comentário.');
        }

        $this->taskRepository->touch($taskId);
        $task = $this->requireTask($taskId);

        $message = $comment;
        $mail = $this->notifyCreator(
            $task,
            'Comentário na task #' . $taskId . ' — ' . $task['subject'],
            $this->buildUpdateBody($task, 'Novo comentário: ' . $comment, $actorName)
        );
        $this->taskRepository->addEvent(
            $taskId,
            'comment',
            $message,
            $actorName,
            $mail['ok'],
            $mail['error']
        );

        return [
            'task' => $task,
            'email_ok' => $mail['ok'],
            'email_error' => $mail['error'],
        ];
    }

    /**
     * @param array{
     *   subject: string,
     *   description: string,
     *   sector: string,
     *   requester_name: string,
     *   requester_email: string,
     *   priority?: string,
     *   actor_name?: string
     * } $input
     * @return array{task: array<string, mixed>, email_ok: bool, email_error: string|null}
     */
    public function updateDetails(int $taskId, array $input): array
    {
        $task = $this->requireTask($taskId);

        $subject = trim((string) ($input['subject'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $sector = trim((string) ($input['sector'] ?? ''));
        $requesterName = trim((string) ($input['requester_name'] ?? ''));
        $requesterEmail = trim((string) ($input['requester_email'] ?? ''));
        $priority = trim((string) ($input['priority'] ?? (string) $task['priority']));
        $actorName = trim((string) ($input['actor_name'] ?? ''));

        if ($subject === '' || $description === '' || $sector === '' || $requesterName === '') {
            throw new RuntimeException('Preencha assunto, descrição, setor e solicitante.');
        }
        if ($requesterEmail === '' || !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido do solicitante.');
        }
        if (!isset(self::PRIORITIES[$priority])) {
            $priority = 'normal';
        }

        $this->taskRepository->update($taskId, [
            'subject' => mb_substr($subject, 0, 200),
            'description' => $description,
            'sector' => mb_substr($sector, 0, 80),
            'requester_name' => mb_substr($requesterName, 0, 120),
            'requester_email' => mb_substr($requesterEmail, 0, 200),
            'priority' => $priority,
        ]);

        $task = $this->requireTask($taskId);
        $message = 'Dados da task atualizados.';
        $mail = $this->notifyCreator(
            $task,
            'Task atualizada #' . $taskId . ' — ' . $subject,
            $this->buildUpdateBody($task, $message, $actorName !== '' ? $actorName : null)
        );
        $this->taskRepository->addEvent(
            $taskId,
            'updated',
            $message,
            $actorName !== '' ? $actorName : null,
            $mail['ok'],
            $mail['error']
        );

        return [
            'task' => $task,
            'email_ok' => $mail['ok'],
            'email_error' => $mail['error'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        return $this->taskRepository->list($filters);
    }

    public function get(int $id): ?array
    {
        return $this->taskRepository->findById($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function events(int $taskId): array
    {
        return $this->taskRepository->listEvents($taskId);
    }

    /**
     * @return list<string>
     */
    public function sectorsForSelect(): array
    {
        $used = $this->taskRepository->listSectorsUsed();
        $merged = array_values(array_unique(array_merge(self::SECTORS, $used)));
        sort($merged, SORT_STRING | SORT_FLAG_CASE);

        return $merged;
    }

    private function requireTask(int $id): array
    {
        $task = $this->taskRepository->findById($id);
        if ($task === null) {
            throw new RuntimeException('Task não encontrada.');
        }

        return $task;
    }

    /**
     * @param array<string, mixed> $task
     * @return array{ok: bool, error: string|null}
     */
    private function notifyCreator(array $task, string $subject, array $bodies): array
    {
        $to = trim((string) ($task['requester_email'] ?? ''));

        return $this->mailService->send($to, $subject, $bodies['text'], $bodies['html']);
    }

    /**
     * @param array<string, mixed> $task
     * @return array{text: string, html: string}
     */
    private function buildCreatedBody(array $task): array
    {
        $id = (int) $task['id'];
        $link = $this->taskUrl($id);
        $status = self::STATUSES[(string) $task['status']] ?? (string) $task['status'];
        $priority = self::PRIORITIES[(string) $task['priority']] ?? (string) $task['priority'];

        $text = "Olá {$task['requester_name']},\n\n"
            . "Sua task #{$id} foi registrada no Portal WCT.\n\n"
            . "Assunto: {$task['subject']}\n"
            . "Setor: {$task['sector']}\n"
            . "Prioridade: {$priority}\n"
            . "Status: {$status}\n\n"
            . "Descrição:\n{$task['description']}\n\n"
            . "Acompanhar: {$link}\n\n"
            . "— Portal WCT\n";

        $html = '<p>Olá <strong>' . htmlspecialchars((string) $task['requester_name'], ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
            . '<p>Sua task <strong>#' . $id . '</strong> foi registrada no Portal WCT.</p>'
            . '<ul>'
            . '<li><strong>Assunto:</strong> ' . htmlspecialchars((string) $task['subject'], ENT_QUOTES, 'UTF-8') . '</li>'
            . '<li><strong>Setor:</strong> ' . htmlspecialchars((string) $task['sector'], ENT_QUOTES, 'UTF-8') . '</li>'
            . '<li><strong>Prioridade:</strong> ' . htmlspecialchars($priority, ENT_QUOTES, 'UTF-8') . '</li>'
            . '<li><strong>Status:</strong> ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</li>'
            . '</ul>'
            . '<p><strong>Descrição:</strong><br>' . nl2br(htmlspecialchars((string) $task['description'], ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Abrir no Portal</a></p>'
            . '<p>— Portal WCT</p>';

        return ['text' => $text, 'html' => $html];
    }

    /**
     * @param array<string, mixed> $task
     * @return array{text: string, html: string}
     */
    private function buildUpdateBody(array $task, string $message, ?string $actorName): array
    {
        $id = (int) $task['id'];
        $link = $this->taskUrl($id);
        $status = self::STATUSES[(string) $task['status']] ?? (string) $task['status'];
        $actor = $actorName !== null && trim($actorName) !== '' ? trim($actorName) : 'Portal WCT';

        $text = "Olá {$task['requester_name']},\n\n"
            . "Houve uma atualização na task #{$id} ({$task['subject']}).\n\n"
            . "{$message}\n\n"
            . "Status atual: {$status}\n"
            . "Registrado por: {$actor}\n\n"
            . "Acompanhar: {$link}\n\n"
            . "— Portal WCT\n";

        $html = '<p>Olá <strong>' . htmlspecialchars((string) $task['requester_name'], ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
            . '<p>Houve uma atualização na task <strong>#' . $id . '</strong> ('
            . htmlspecialchars((string) $task['subject'], ENT_QUOTES, 'UTF-8') . ').</p>'
            . '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<p><strong>Status atual:</strong> ' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Registrado por:</strong> ' . htmlspecialchars($actor, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">Abrir no Portal</a></p>'
            . '<p>— Portal WCT</p>';

        return ['text' => $text, 'html' => $html];
    }

    private function taskUrl(int $id): string
    {
        return portal_wct_absolute_url($this->baseUrl, 'index.php?page=tasks&id=' . $id);
    }
}
