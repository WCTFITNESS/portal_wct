<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$latestManualMessage = $app['logRepository']->getLatestManualMessage();
$recentManualMessages = $app['logRepository']->listRecentManualMessages(10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'process-orders') {
            $total = $app['orderService']->processCompletedOrders();
            $feedback = "Rotina executada. Pedidos processados: {$total}.";
        }

        if (($_POST['action'] ?? '') === 'manual-message') {
            $apiConfig = $app['settingsRepository']->getApiConfig();
            if (!$apiConfig) {
                throw new RuntimeException('Configure a API antes do envio manual.');
            }

            $orderIdsInput = trim((string) ($_POST['order_id'] ?? ''));
            $messageBody = trim((string) ($_POST['message_body'] ?? ''));

            if ($orderIdsInput === '' || $messageBody === '') {
                throw new RuntimeException('Preencha ID(s) do pedido e mensagem.');
            }

            $orderIds = array_values(array_filter(array_map(
                static fn(string $v): string => trim($v),
                explode(',', $orderIdsInput)
            ), static fn(string $v): bool => $v !== ''));

            if ($orderIds === []) {
                throw new RuntimeException('Informe pelo menos um ID de pedido válido.');
            }

            $batch = $app['messageService']->sendManualBatch((string) $apiConfig['seller_id'], $orderIds, $messageBody);
            $feedback = 'Envio manual concluído. Total: ' . (int) ($batch['total'] ?? 0)
                . ' | Enviadas: ' . (int) ($batch['sent'] ?? 0)
                . ' | Erros: ' . (int) ($batch['errors'] ?? 0);
            if ((int) ($batch['errors'] ?? 0) > 0) {
                $feedbackClass = 'err';
            }

            $latestManualMessage = $app['logRepository']->getLatestManualMessage();
            $recentManualMessages = $app['logRepository']->listRecentManualMessages(10);
        }
    } catch (Throwable $exception) {
        $feedback = 'Erro: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}
?>
<section class="card">
    <h1>Envio e Rotina</h1>
    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="process-orders">
        <p>Executa agora a rotina de pedidos pagos e dispara a mensagem de agradecimento.</p>
        <button type="submit">Processar Pedidos Pagos Agora</button>
    </form>
</section>

<section class="card">
    <h1>Teste de Mensagem Manual</h1>
    <p>Você pode informar mais de um pedido separado por vírgula. Ex.: <code>2000000001,2000000002,2000000003</code></p>
    <form method="post">
        <input type="hidden" name="action" value="manual-message">
        <label>ID(s) do pedido</label>
        <input type="text" name="order_id" required>

        <label>Mensagem</label>
        <textarea name="message_body" rows="5" required>Olá! Obrigado pela sua compra.</textarea>

        <button type="submit">Enviar Mensagem de Teste</button>
    </form>

    <?php if ($latestManualMessage): ?>
        <h2 style="margin-top:18px;">Última mensagem manual enviada</h2>
        <p>
            Pedido: <strong><?= htmlspecialchars((string) ($latestManualMessage['order_id'] ?? '')) ?></strong> |
            Sender: <strong><?= htmlspecialchars((string) ($latestManualMessage['sender_id'] ?? '')) ?></strong> |
            Status: <strong><?= htmlspecialchars((string) ($latestManualMessage['status'] ?? '')) ?></strong> |
            Data: <strong><?= htmlspecialchars((string) ($latestManualMessage['sent_at'] ?? '')) ?></strong>
        </p>
        <p><?= nl2br(htmlspecialchars((string) ($latestManualMessage['message_body'] ?? ''))) ?></p>
    <?php endif; ?>

    <h2 style="margin-top:18px;">Últimos 10 envios manuais</h2>
    <div style="overflow-x:auto;">
        <table>
            <thead>
            <tr>
                <th>Data</th>
                <th>Pedido</th>
                <th>Sender</th>
                <th>Status</th>
                <th>Mensagem</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($recentManualMessages)): ?>
                <tr>
                    <td colspan="5">Nenhum envio manual encontrado.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($recentManualMessages as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($row['sent_at'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['order_id'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['sender_id'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['status'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) mb_strimwidth((string) ($row['message_body'] ?? ''), 0, 120, '...')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
