<?php

declare(strict_types=1);

$apiConfig = $app['settingsRepository']->getApiConfig();
$token = $app['tokenRepository']->getLatestToken();
$template = $app['templateRepository']->getActiveTemplate();
$logs = $app['logRepository']->listRecent(10);
$lexos = $app['lexosDashboardService'];

$today = new DateTimeImmutable('now');
$monthStart = $today->modify('first day of this month');
$dStart = trim((string) ($_GET['lexos_start'] ?? $monthStart->format('Y-m-d')));
$dEnd = trim((string) ($_GET['lexos_end'] ?? $today->format('Y-m-d')));
$search = trim((string) ($_GET['lexos_search'] ?? ''));
$sku = trim((string) ($_GET['lexos_sku'] ?? ''));
$metrics = null;
$channels = [];
$products = [];
$skuData = null;
$lexosError = null;

try {
    if (($apiConfig['lexos_token'] ?? '') !== '') {
        $metrics = $lexos->getDashboardMetrics($dStart, $dEnd);
        $channels = is_array($metrics['canais'] ?? null) ? $metrics['canais'] : [];
        $products = $lexos->getProducts($dStart, $dEnd, $search, 50);
        if ($sku !== '') {
            $skuData = $lexos->getSkuAnalysis($sku, $dStart, $dEnd);
        }
    }
} catch (Throwable $e) {
    $lexosError = $e->getMessage();
}
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
    <h1>Dashboard Lexos (Plugin Faturamento)</h1>
    <p>Dados trazidos do plugin Chrome para dentro do portal.</p>
    <?php if (($apiConfig['lexos_token'] ?? '') === ''): ?>
        <div class="msg err">Configure o <strong>Token Lexos</strong> em Configuração API para habilitar esta seção.</div>
    <?php elseif ($lexosError): ?>
        <div class="msg err">Erro Lexos: <?= htmlspecialchars($lexosError) ?></div>
    <?php endif; ?>

    <form method="get">
        <input type="hidden" name="page" value="dashboard">
        <label>Data inicial</label>
        <input type="date" name="lexos_start" value="<?= htmlspecialchars($dStart) ?>">
        <label>Data final</label>
        <input type="date" name="lexos_end" value="<?= htmlspecialchars($dEnd) ?>">
        <label>Buscar produto (SKU/Nome/EAN)</label>
        <input type="text" name="lexos_search" value="<?= htmlspecialchars($search) ?>">
        <label>Análise SKU (opcional)</label>
        <input type="text" name="lexos_sku" value="<?= htmlspecialchars($sku) ?>">
        <button type="submit">Atualizar Dashboard Lexos</button>
    </form>

    <?php if (is_array($metrics)): ?>
        <p style="margin-top:12px;">
            Faturamento: <strong><?= htmlspecialchars(number_format((float) ($metrics['faturamento'] ?? 0), 2, ',', '.')) ?></strong> |
            Pedidos: <strong><?= htmlspecialchars((string) ($metrics['pedidos'] ?? 0)) ?></strong> |
            Ticket médio: <strong><?= htmlspecialchars(number_format((float) ($metrics['ticket_medio'] ?? 0), 2, ',', '.')) ?></strong>
        </p>
    <?php endif; ?>
</section>

<?php if ($channels): ?>
<section class="card">
    <h1>Faturamento por Canal (Lexos)</h1>
    <table>
        <thead><tr><th>Canal</th><th>Faturamento</th></tr></thead>
        <tbody>
        <?php foreach ($channels as $row): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($row[0] ?? '')) ?></td>
                <td><?= htmlspecialchars(number_format((float) ($row[1] ?? 0), 2, ',', '.')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php if ($products): ?>
<section class="card">
    <h1>Produtos (Lexos)</h1>
    <table>
        <thead>
        <tr>
            <th>SKU</th><th>Nome</th><th>EAN</th><th>Estoque</th><th>Faturamento</th><th>Quantidade</th><th>Classificação</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($products as $p): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($p['Sku'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($p['Nome'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($p['Ean'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($p['Estoque'] ?? '')) ?></td>
                <td><?= htmlspecialchars(number_format((float) ($p['TotalVendidoItem'] ?? 0), 2, ',', '.')) ?></td>
                <td><?= htmlspecialchars((string) ($p['TotalUnidadesVendidas'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($p['Classificacao'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<?php if (is_array($skuData)): ?>
<section class="card">
    <h1>Análise SKU (Lexos)</h1>
    <p>SKU consultado: <strong><?= htmlspecialchars($sku) ?></strong></p>
    <p>Registros de venda: <strong><?= htmlspecialchars((string) count($skuData['sales'] ?? [])) ?></strong></p>
    <p>Registros de estoque: <strong><?= htmlspecialchars((string) count($skuData['stock'] ?? [])) ?></strong></p>
</section>
<?php endif; ?>

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
