<?php

declare(strict_types=1);

$apiConfig = $app['settingsRepository']->getApiConfig();
$token = $app['tokenRepository']->getLatestToken();
$template = $app['templateRepository']->getActiveTemplate();
$logs = $app['logRepository']->listRecent(10);
?>
<section class="card">
    <h1>Portal WCT</h1>
    <p>Integração modular para envio de mensagem de agradecimento após compra finalizada.</p>
    <ul>
        <li>API configurada: <strong><?= $apiConfig ? 'Sim' : 'Não' ?></strong></li>
        <li>Token salvo: <strong><?= $token ? 'Sim' : 'Não' ?></strong></li>
        <li>Template ativo: <strong><?= $template ? 'Sim' : 'Não' ?></strong></li>
    </ul>
    <p>Para processar pedidos automaticamente, configure o Agendador do Windows para executar: <code>php cron/process_completed_orders.php</code>.</p>
</section>

<section class="card">
    <h1>Últimos envios</h1>
    <table>
        <thead>
        <tr>
            <th>Pedido</th>
            <th>Cliente</th>
            <th>Status</th>
            <th>Data</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$logs): ?>
            <tr><td colspan="4">Nenhum envio registrado ainda.</td></tr>
        <?php endif; ?>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= htmlspecialchars((string) $log['order_id']) ?></td>
                <td><?= htmlspecialchars((string) $log['receiver_id']) ?></td>
                <td>
                    <span class="badge <?= $log['status'] === 'sent' ? 'sent' : 'error' ?>">
                        <?= htmlspecialchars((string) $log['status']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars((string) $log['sent_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
