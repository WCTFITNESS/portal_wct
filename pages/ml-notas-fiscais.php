<?php

declare(strict_types=1);

use App\Services\MlInvoiceBatchService;

$feedback = isset($_GET['flash_err']) && $_GET['flash_err'] !== '' ? (string) $_GET['flash_err'] : null;
$de = (string) ($_GET['de'] ?? date('Y-m-01'));
$ate = (string) ($_GET['ate'] ?? date('Y-m-d'));
$tiposMarcados = is_array($_GET['tipos'] ?? null) ? array_map('strval', $_GET['tipos']) : ['full_inbound', 'full_retorno', 'full_retirada'];
$incluirPdf = !empty($_GET['pdf']);
?>
<style>
    .mlnf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
    .mlnf-tipos { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 6px 16px; margin-top: 8px; }
    .mlnf-tipos label, .mlnf-check { display: flex; align-items: center; gap: 8px; font-weight: normal; margin-top: 4px; }
    .mlnf-tipos input, .mlnf-check input { width: auto; margin: 0; }
    .mlnf-hint { color: #64748b; font-size: .85rem; margin: 6px 0 0; }
    .mlnf-presets { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
    .mlnf-presets button { width: auto; margin-top: 0; padding: 6px 10px; font-size: .75rem; background: #fff; color: #111; }
</style>

<section class="card">
    <h1>Notas fiscais do Mercado Livre (XML)</h1>
    <p class="mlnf-hint" style="margin-top:0">
        Baixa direto do Mercado Livre o ZIP com os XML das notas emitidas pelo Faturador do ML — inclusive as do <strong>Full</strong>,
        que não chegam sozinhas no Protheus. O ZIP vem separado em pastas por tipo de nota, pronto para importar.
    </p>

    <?php if ($feedback !== null): ?>
        <p class="msg err"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="get" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php')) ?>" id="mlnf-form">
        <input type="hidden" name="page" value="ml-notas-fiscais">
        <input type="hidden" name="download" value="1">
        <div class="mlnf-grid">
            <div><label>De</label><input type="date" name="de" value="<?= htmlspecialchars($de) ?>" required></div>
            <div><label>Até</label><input type="date" name="ate" value="<?= htmlspecialchars($ate) ?>" required></div>
        </div>

        <label style="margin-top:16px">Tipos de nota</label>
        <div class="mlnf-presets">
            <button type="button" data-preset="full_inbound,full_retorno,full_retirada">Só Full</button>
            <button type="button" data-preset="venda">Só vendas</button>
            <button type="button" data-preset="<?= htmlspecialchars(implode(',', array_keys(MlInvoiceBatchService::TIPOS))) ?>">Tudo</button>
        </div>
        <div class="mlnf-tipos">
            <?php foreach (MlInvoiceBatchService::TIPOS as $key => $tipo): ?>
                <label>
                    <input type="checkbox" name="tipos[]" value="<?= htmlspecialchars($key) ?>"<?= in_array($key, $tiposMarcados, true) ? ' checked' : '' ?>>
                    <?= htmlspecialchars($tipo['label']) ?>
                </label>
            <?php endforeach; ?>
        </div>
        <label class="mlnf-check" style="margin-top:12px">
            <input type="checkbox" name="pdf" value="1"<?= $incluirPdf ? ' checked' : '' ?>> Incluir também o PDF (DANFE)
        </label>

        <button type="submit" style="width:auto">Baixar ZIP</button>
        <p class="mlnf-hint">
            Períodos longos (um mês inteiro com PDF) podem levar alguns minutos para o Mercado Livre montar o arquivo — o download começa quando ele terminar.
            Máximo de 92 dias por vez.
        </p>
    </form>
</section>

<script>
(function () {
    document.querySelectorAll('[data-preset]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wanted = btn.getAttribute('data-preset').split(',');
            document.querySelectorAll('#mlnf-form input[name="tipos[]"]').forEach(function (cb) {
                cb.checked = wanted.indexOf(cb.value) !== -1;
            });
        });
    });
})();
</script>
