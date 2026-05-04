<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $title = trim((string) ($_POST['title'] ?? 'Mensagem padrão'));
        $body = trim((string) ($_POST['body'] ?? ''));

        if ($body === '') {
            throw new RuntimeException('Preencha o corpo da mensagem.');
        }

        $app['templateRepository']->saveTemplate($title, $body);
        $feedback = 'Template salvo e ativado.';
    } catch (Throwable $exception) {
        $feedback = 'Erro: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

$template = $app['templateRepository']->getActiveTemplate();
?>
<section class="card">
    <h1>Mensagem de Agradecimento</h1>
    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <p>Use variáveis: <code>{{nome_cliente}}</code> e <code>{{pedido_id}}</code>.</p>

    <form method="post">
        <label>Título interno</label>
        <input type="text" name="title" value="<?= htmlspecialchars((string) ($template['title'] ?? 'Mensagem padrão')) ?>" required>

        <label>Mensagem</label>
        <textarea name="body" rows="8" required><?= htmlspecialchars((string) ($template['body'] ?? 'Olá {{nome_cliente}}, obrigado pela sua compra! Pedido {{pedido_id}} confirmado com sucesso.')) ?></textarea>

        <button type="submit">Salvar Mensagem</button>
    </form>
</section>
