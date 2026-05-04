<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$orders = [];
$status = trim((string) ($_GET['status'] ?? 'paid'));
$limit = (int) ($_GET['limit'] ?? 20);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

if ($status === '') {
    $status = 'paid';
}

if ($limit < 1 || $limit > 50) {
    $limit = 20;
}

try {
    $orders = $app['orderService']->listOrders($status, $limit, $dateFrom, $dateTo);
} catch (Throwable $exception) {
    $feedback = 'Erro ao buscar pedidos: ' . $exception->getMessage();
    $feedbackClass = 'err';
}

$flattenOrder = static function (array $data, string $prefix = '') use (&$flattenOrder): array {
    $result = [];

    foreach ($data as $key => $value) {
        $keyName = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;

        if (is_array($value)) {
            if ($value === []) {
                $result[$keyName] = '';
                continue;
            }

            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                $result = array_merge($result, $flattenOrder($value, $keyName));
                continue;
            }

            $result[$keyName] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            continue;
        }

        if (is_bool($value)) {
            $result[$keyName] = $value ? 'true' : 'false';
            continue;
        }

        $result[$keyName] = $value === null ? '' : (string) $value;
    }

    return $result;
};

$flatOrders = [];
$allColumns = [];
foreach ($orders as $order) {
    $flat = $flattenOrder($order);
    $flatOrders[] = $flat;
    foreach (array_keys($flat) as $columnName) {
        $allColumns[$columnName] = true;
    }
}
$columnList = array_keys($allColumns);
sort($columnList);
?>
<section class="card">
    <h1>Pedidos Mercado Livre</h1>
    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="get">
        <input type="hidden" name="page" value="orders">
        <label>Status do pedido</label>
        <input type="text" name="status" value="<?= htmlspecialchars($status) ?>" placeholder="paid, cancelled, confirmed...">

        <label>Limite (1 a 50)</label>
        <input type="number" name="limit" min="1" max="50" value="<?= htmlspecialchars((string) $limit) ?>">

        <label>Data inicial</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">

        <label>Data final</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">

        <button type="submit">Atualizar Lista</button>
    </form>
</section>

<style>
    .ml-modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 1000;
        align-items: center;
        justify-content: center;
        background: rgba(0, 0, 0, 0.45);
        padding: 16px;
        box-sizing: border-box;
    }
    .ml-modal-backdrop.is-open { display: flex; }
    .ml-modal-panel {
        background: #fff;
        border-radius: 10px;
        max-width: min(960px, 100%);
        max-height: 88vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
        overflow: hidden;
    }
    .ml-modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 16px;
        border-bottom: 1px solid #e8edf5;
        font-weight: bold;
    }
    .ml-modal-body {
        padding: 12px 16px;
        overflow: auto;
        flex: 1;
    }
    .ml-modal-body pre {
        margin: 0;
        font-size: 12px;
        line-height: 1.45;
        white-space: pre-wrap;
        word-break: break-word;
        background: #f8fafc;
        border: 1px solid #e8edf5;
        border-radius: 6px;
        padding: 12px;
    }
    button.ml-btn-json {
        margin-top: 0;
        padding: 6px 10px;
        font-size: 0.8rem;
        background: #334155;
    }
    button.ml-btn-json:hover { background: #1e293b; }
    button.ml-modal-close {
        margin-top: 0;
        padding: 6px 12px;
        background: #64748b;
    }
</style>

<section class="card">
    <h1>Resultados completos em tabela</h1>
    <p>Todos os dados dos pedidos em formato tabular. Campos aninhados aparecem com notação por ponto (ex.: <code>buyer.nickname</code>). Use o botão <strong>Ver JSON completo</strong> para abrir o retorno bruto da API em um modal.</p>
    <div style="overflow-x:auto; width:100%;">
        <table style="min-width: 1600px;">
            <thead>
            <tr>
                <th style="min-width: 150px; position: sticky; left: 0; background: #fff; z-index: 1; box-shadow: 2px 0 4px rgba(0,0,0,.06);">JSON</th>
                <?php if (!$columnList): ?>
                    <th>—</th>
                <?php else: ?>
                    <?php foreach ($columnList as $column): ?>
                        <th><?= htmlspecialchars($column) ?></th>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php if (!$flatOrders): ?>
                <tr><td colspan="<?= max(2, count($columnList) + 1) ?>">Nenhum pedido encontrado para os filtros informados.</td></tr>
            <?php endif; ?>
            <?php foreach ($flatOrders as $idx => $flatOrder): ?>
                <tr>
                    <td style="position: sticky; left: 0; background: #fff; z-index: 1; box-shadow: 2px 0 4px rgba(0,0,0,.06); vertical-align: top;">
                        <button type="button" class="ml-btn-json" data-order-index="<?= (int) $idx ?>">Ver JSON completo</button>
                    </td>
                    <?php foreach ($columnList as $column): ?>
                        <td><?= htmlspecialchars((string) ($flatOrder[$column] ?? '')) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script type="application/json" id="ml-orders-json-data"><?= json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>

<div id="ml-order-json-modal" class="ml-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="ml-order-json-modal-title">
    <div class="ml-modal-panel">
        <div class="ml-modal-header">
            <span id="ml-order-json-modal-title">JSON completo do pedido</span>
            <button type="button" class="ml-modal-close" id="ml-order-json-modal-close">Fechar</button>
        </div>
        <div class="ml-modal-body">
            <pre id="ml-order-json-modal-pre"></pre>
        </div>
    </div>
</div>

<script>
(function () {
    var dataEl = document.getElementById('ml-orders-json-data');
    var modal = document.getElementById('ml-order-json-modal');
    var pre = document.getElementById('ml-order-json-modal-pre');
    var title = document.getElementById('ml-order-json-modal-title');
    var closeBtn = document.getElementById('ml-order-json-modal-close');
    if (!dataEl || !modal || !pre) return;

    var orders = [];
    try {
        orders = JSON.parse(dataEl.textContent || '[]');
    } catch (e) {
        orders = [];
    }

    function openModal(index) {
        var o = orders[index];
        if (o === undefined) return;
        var id = (o && o.id !== undefined && o.id !== null) ? String(o.id) : String(index);
        title.textContent = 'JSON completo do pedido #' + id;
        try {
            pre.textContent = JSON.stringify(o, null, 2);
        } catch (e2) {
            pre.textContent = String(o);
        }
        modal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('.ml-btn-json').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var i = parseInt(btn.getAttribute('data-order-index'), 10);
            if (!isNaN(i)) openModal(i);
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (ev) {
        if (ev.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
})();
</script>
