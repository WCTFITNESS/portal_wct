<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$summary = null;
$downloadFile = null;
$mpSettingsRepo = $app['mercadopagoSettingsRepository'];
$repasseMpService = $app['repasseMpService'];
$mpPaymentService = $app['mercadopagoPaymentService'];
$lookupResult = null;
$lookupMode = trim((string) ($_POST['lookup_mode'] ?? 'operacao_relacionada'));
$lookupValue = trim((string) ($_POST['lookup_value'] ?? ''));
$lookupDateFrom = trim((string) ($_POST['lookup_date_from'] ?? ''));
$lookupDateTo = trim((string) ($_POST['lookup_date_to'] ?? ''));
$lookupPage = max(1, (int) ($_POST['lookup_page'] ?? 1));
$salesLookupResult = null;
$salesLookupMode = trim((string) ($_POST['sales_lookup_mode'] ?? 'operacao_relacionada'));
$salesLookupValue = trim((string) ($_POST['sales_lookup_value'] ?? ''));
$salesLookupDateFrom = trim((string) ($_POST['sales_lookup_date_from'] ?? ''));
$salesLookupDateTo = trim((string) ($_POST['sales_lookup_date_to'] ?? ''));
$salesLookupPage = max(1, (int) ($_POST['sales_lookup_page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['form_type'] ?? '') === 'mp_token') {
            $mpSettingsRepo->saveAccessToken(trim((string) ($_POST['mp_access_token'] ?? '')));
            $feedback = 'Access token do Mercado Pago salvo.';
        }

        if (($_POST['form_type'] ?? '') === 'mp_upload') {
            $result = $repasseMpService->processUploadedFile($_FILES['repasse_mp_file'] ?? []);
            $summary = $result;
            $downloadFile = $result['file_name'] ?? null;
            $feedback = 'Arquivo processado com sucesso. Baixe a planilha com a coluna order.';
        }

        if (($_POST['form_type'] ?? '') === 'mp_lookup') {
            $lookupResult = $mpPaymentService->searchStandalone($lookupMode, $lookupValue, $lookupDateFrom, $lookupDateTo, $lookupPage, 50);
            $feedback = (string) ($lookupResult['message'] ?? 'Busca avulsa concluida.');
        }

        if (($_POST['form_type'] ?? '') === 'mp_sales_lookup') {
            $salesLookupResult = $mpPaymentService->searchSalesReportStandalone(
                $salesLookupMode,
                $salesLookupValue,
                $salesLookupDateFrom,
                $salesLookupDateTo,
                $salesLookupPage,
                50
            );
            $feedback = (string) ($salesLookupResult['message'] ?? 'Consulta no relatorio de vendas concluida.');
        }
    } catch (Throwable $exception) {
        $feedback = 'Erro: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

$mpRow = $mpSettingsRepo->getSettings();
$tokenPreview = $mpRow && trim((string) ($mpRow['access_token'] ?? '')) !== ''
    ? 'Access token do Mercado Pago esta salvo.'
    : 'Nenhum token salvo ainda.';
?>
<section class="card">
    <h1>Repasse MP</h1>
    <p>Upload de planilha para buscar <code>order.id</code> no Mercado Pago. O sistema usa a <strong>coluna D</strong> como filtro e deduplica os valores para evitar chamadas repetidas na API.</p>
    <p>Mesmo com deduplicacao, todas as linhas originais sao mantidas no Excel final; linhas duplicadas recebem o mesmo <code>order</code> encontrado.</p>

    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <p style="margin-top:8px;font-size:.9rem;color:#555;"><?= htmlspecialchars($tokenPreview) ?></p>

    <form method="post" style="margin-top:16px;">
        <input type="hidden" name="form_type" value="mp_token">
        <label>Access token (Mercado Pago)</label>
        <textarea name="mp_access_token" rows="3" placeholder="APP_USR-..."><?= htmlspecialchars((string) ($mpRow['access_token'] ?? '')) ?></textarea>
        <button type="submit">Salvar token</button>
    </form>
</section>

<section class="card">
    <h1>Processar arquivo</h1>
    <p>Formatos aceitos: XLS, XLSX e CSV.</p>
    <style>
        .mp-processing-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            z-index: 3000;
            align-items: center;
            justify-content: center;
        }
        .mp-processing-overlay.is-open { display: flex; }
        .mp-processing-box {
            background: #fff;
            border-radius: 10px;
            padding: 18px 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .2);
            font-weight: bold;
        }
        .mp-processing-box small {
            display: block;
            margin-top: 8px;
            color: #666;
            font-weight: normal;
        }
    </style>

    <form method="post" enctype="multipart/form-data" id="repasse-mp-upload-form">
        <input type="hidden" name="form_type" value="mp_upload">
        <label>Arquivo de repasse MP</label>
        <input type="file" name="repasse_mp_file" accept=".xls,.xlsx,.csv,.XLS,.XLSX,.CSV" required>
        <button type="submit" id="repasse-mp-submit-btn">Processar arquivo</button>
    </form>
    <div id="mp-processing-overlay" class="mp-processing-overlay" aria-live="polite" aria-busy="true">
        <div class="mp-processing-box">
            Processando arquivo, aguarde...
            <small>Nao feche a pagina durante o processamento.</small>
        </div>
    </div>

    <?php if ($downloadFile): ?>
        <p>
            Processadas: <strong><?= htmlspecialchars((string) ($summary['processed'] ?? 0)) ?></strong> |
            Operacoes unicas (coluna D): <strong><?= htmlspecialchars((string) ($summary['unique_operations'] ?? 0)) ?></strong> |
            Coluna detectada: <strong><?= htmlspecialchars((string) ((int) ($summary['operation_column_index'] ?? 3) + 1)) ?></strong> |
            Nome da coluna detectada: <strong><?= htmlspecialchars((string) ($summary['operation_column_name'] ?? '')) ?></strong> |
            Encontradas: <strong><?= htmlspecialchars((string) ($summary['found'] ?? 0)) ?></strong> |
            Nao encontradas: <strong><?= htmlspecialchars((string) ($summary['not_found'] ?? 0)) ?></strong> |
            Erros: <strong><?= htmlspecialchars((string) ($summary['errors'] ?? 0)) ?></strong> |
            Chamadas API: <strong><?= htmlspecialchars((string) ($summary['api_calls'] ?? 0)) ?></strong>
        </p>
        <p>
            <a href="<?= htmlspecialchars($baseUrl) ?>/index.php?page=repasse-mp&download=<?= urlencode((string) $downloadFile) ?>">
                Baixar planilha com coluna order
            </a>
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <h1>Busca avulsa</h1>
    <p>Consulta individual por operacao relacionada, numero do movimento, numero de pedido ou data.</p>

    <form method="post">
        <input type="hidden" name="form_type" value="mp_lookup">
        <input type="hidden" name="lookup_page" value="<?= htmlspecialchars((string) $lookupPage) ?>">

        <label>Tipo de busca</label>
        <select name="lookup_mode" id="mp-lookup-mode" style="width:100%;padding:10px;margin-top:6px;border-radius:6px;border:1px solid #ccd3df;">
            <option value="operacao_relacionada" <?= $lookupMode === 'operacao_relacionada' ? 'selected' : '' ?>>Operacao relacionada</option>
            <option value="numero_movimento" <?= $lookupMode === 'numero_movimento' ? 'selected' : '' ?>>Numero do movimento</option>
            <option value="numero_pedido" <?= $lookupMode === 'numero_pedido' ? 'selected' : '' ?>>Numero do pedido (order.id)</option>
            <option value="data" <?= $lookupMode === 'data' ? 'selected' : '' ?>>Data</option>
        </select>

        <div id="mp-lookup-value-block" style="display: <?= $lookupMode === 'data' ? 'none' : 'block' ?>;">
            <label>Valor da busca</label>
            <input type="text" name="lookup_value" value="<?= htmlspecialchars($lookupValue) ?>" placeholder="Ex.: 148328824516">
        </div>

        <label>Data inicial (opcional)</label>
        <input type="date" name="lookup_date_from" value="<?= htmlspecialchars($lookupDateFrom) ?>">

        <label>Data final (opcional)</label>
        <input type="date" name="lookup_date_to" value="<?= htmlspecialchars($lookupDateTo) ?>">

        <button type="submit">Pesquisar</button>
    </form>

    <?php if (is_array($lookupResult)): ?>
        <?php $lookupItems = $lookupResult['items'] ?? []; ?>
        <?php $paging = $lookupResult['paging'] ?? ['page' => 1, 'total_pages' => 1, 'total' => count($lookupItems), 'page_size' => 50]; ?>
        <?php
            $lookupJson = json_encode(
                $lookupResult['responses'] ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $lookupJsonB64 = $lookupJson !== false ? base64_encode($lookupJson) : '';
        ?>
        <p style="margin-top:14px;">
            Registros: <strong><?= htmlspecialchars((string) count($lookupItems)) ?></strong> |
            Total encontrado: <strong><?= htmlspecialchars((string) ($paging['total'] ?? count($lookupItems))) ?></strong> |
            Pagina: <strong><?= htmlspecialchars((string) ($paging['page'] ?? 1)) ?></strong> / <strong><?= htmlspecialchars((string) ($paging['total_pages'] ?? 1)) ?></strong> |
            Chamadas API: <strong><?= htmlspecialchars((string) ($lookupResult['api_calls'] ?? 0)) ?></strong>
        </p>
        <?php
            $curPage = (int) ($paging['page'] ?? 1);
            $totalPages = (int) ($paging['total_pages'] ?? 1);
        ?>
        <?php if ($totalPages > 1): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 14px 0;">
                <?php if ($curPage > 1): ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="form_type" value="mp_lookup">
                        <input type="hidden" name="lookup_mode" value="<?= htmlspecialchars($lookupMode) ?>">
                        <input type="hidden" name="lookup_value" value="<?= htmlspecialchars($lookupValue) ?>">
                        <input type="hidden" name="lookup_date_from" value="<?= htmlspecialchars($lookupDateFrom) ?>">
                        <input type="hidden" name="lookup_date_to" value="<?= htmlspecialchars($lookupDateTo) ?>">
                        <input type="hidden" name="lookup_page" value="<?= htmlspecialchars((string) ($curPage - 1)) ?>">
                        <button type="submit" style="margin-top:0;">Pagina anterior</button>
                    </form>
                <?php endif; ?>
                <?php if ($curPage < $totalPages): ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="form_type" value="mp_lookup">
                        <input type="hidden" name="lookup_mode" value="<?= htmlspecialchars($lookupMode) ?>">
                        <input type="hidden" name="lookup_value" value="<?= htmlspecialchars($lookupValue) ?>">
                        <input type="hidden" name="lookup_date_from" value="<?= htmlspecialchars($lookupDateFrom) ?>">
                        <input type="hidden" name="lookup_date_to" value="<?= htmlspecialchars($lookupDateTo) ?>">
                        <input type="hidden" name="lookup_page" value="<?= htmlspecialchars((string) ($curPage + 1)) ?>">
                        <button type="submit" style="margin-top:0;">Proxima pagina</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <p>
            <button type="button" id="mp-lookup-json-btn" data-json-b64="<?= htmlspecialchars($lookupJsonB64, ENT_QUOTES, 'UTF-8') ?>">Ver JSON da consulta</button>
        </p>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                <tr>
                    <th>payment_id</th>
                    <th>order.id</th>
                    <th>external_reference</th>
                    <th>status</th>
                    <th>date_created</th>
                    <th>transaction_amount</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($lookupItems)): ?>
                    <tr><td colspan="6">Nenhum resultado para os filtros informados.</td></tr>
                <?php else: ?>
                    <?php foreach ($lookupItems as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($it['id'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['order']['id'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['external_reference'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['status'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['date_created'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['transaction_amount'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if (!empty($summary['preview']) && is_array($summary['preview'])): ?>
<section class="card">
    <h1>Previa</h1>
    <table>
        <thead>
        <tr>
            <th>Linha</th>
            <th>Coluna D</th>
            <th>order</th>
            <th>payment_id</th>
            <th>Duplicadas</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($summary['preview'] as $item): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($item['linha'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($item['coluna_d'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($item['order'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($item['payment_id'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($item['duplicadas'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($item['status_consulta'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

<section class="card">
    <h1>Consulta Avulsa - Relatorio de Vendas</h1>
    <p>Busca no endpoint de relatorio de vendas com os mesmos filtros da consulta avulsa anterior.</p>

    <form method="post">
        <input type="hidden" name="form_type" value="mp_sales_lookup">
        <input type="hidden" name="sales_lookup_page" value="<?= htmlspecialchars((string) $salesLookupPage) ?>">

        <label>Tipo de busca</label>
        <select name="sales_lookup_mode" id="mp-sales-lookup-mode" style="width:100%;padding:10px;margin-top:6px;border-radius:6px;border:1px solid #ccd3df;">
            <option value="operacao_relacionada" <?= $salesLookupMode === 'operacao_relacionada' ? 'selected' : '' ?>>Operacao relacionada</option>
            <option value="numero_movimento" <?= $salesLookupMode === 'numero_movimento' ? 'selected' : '' ?>>Numero do movimento</option>
            <option value="numero_pedido" <?= $salesLookupMode === 'numero_pedido' ? 'selected' : '' ?>>Numero do pedido</option>
            <option value="data" <?= $salesLookupMode === 'data' ? 'selected' : '' ?>>Data</option>
        </select>

        <div id="mp-sales-lookup-value-block" style="display: <?= $salesLookupMode === 'data' ? 'none' : 'block' ?>;">
            <label>Valor da busca</label>
            <input type="text" name="sales_lookup_value" value="<?= htmlspecialchars($salesLookupValue) ?>" placeholder="Ex.: 148328824516">
        </div>

        <label>Data inicial (opcional)</label>
        <input type="date" name="sales_lookup_date_from" value="<?= htmlspecialchars($salesLookupDateFrom) ?>">

        <label>Data final (opcional)</label>
        <input type="date" name="sales_lookup_date_to" value="<?= htmlspecialchars($salesLookupDateTo) ?>">

        <button type="submit">Pesquisar relatorio</button>
    </form>

    <?php if (is_array($salesLookupResult)): ?>
        <?php $salesItems = $salesLookupResult['items'] ?? []; ?>
        <?php $salesPaging = $salesLookupResult['paging'] ?? ['page' => 1, 'total_pages' => 1, 'total' => count($salesItems), 'page_size' => 50]; ?>
        <?php
            $salesJson = json_encode(
                $salesLookupResult['responses'] ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $salesJsonB64 = $salesJson !== false ? base64_encode($salesJson) : '';
            $salesCurPage = (int) ($salesPaging['page'] ?? 1);
            $salesTotalPages = (int) ($salesPaging['total_pages'] ?? 1);
        ?>
        <p style="margin-top:14px;">
            Registros: <strong><?= htmlspecialchars((string) count($salesItems)) ?></strong> |
            Total encontrado: <strong><?= htmlspecialchars((string) ($salesPaging['total'] ?? count($salesItems))) ?></strong> |
            Pagina: <strong><?= htmlspecialchars((string) $salesCurPage) ?></strong> / <strong><?= htmlspecialchars((string) $salesTotalPages) ?></strong> |
            Chamadas API: <strong><?= htmlspecialchars((string) ($salesLookupResult['api_calls'] ?? 0)) ?></strong>
        </p>
        <?php if ($salesTotalPages > 1): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 14px 0;">
                <?php if ($salesCurPage > 1): ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="form_type" value="mp_sales_lookup">
                        <input type="hidden" name="sales_lookup_mode" value="<?= htmlspecialchars($salesLookupMode) ?>">
                        <input type="hidden" name="sales_lookup_value" value="<?= htmlspecialchars($salesLookupValue) ?>">
                        <input type="hidden" name="sales_lookup_date_from" value="<?= htmlspecialchars($salesLookupDateFrom) ?>">
                        <input type="hidden" name="sales_lookup_date_to" value="<?= htmlspecialchars($salesLookupDateTo) ?>">
                        <input type="hidden" name="sales_lookup_page" value="<?= htmlspecialchars((string) ($salesCurPage - 1)) ?>">
                        <button type="submit" style="margin-top:0;">Pagina anterior</button>
                    </form>
                <?php endif; ?>
                <?php if ($salesCurPage < $salesTotalPages): ?>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="form_type" value="mp_sales_lookup">
                        <input type="hidden" name="sales_lookup_mode" value="<?= htmlspecialchars($salesLookupMode) ?>">
                        <input type="hidden" name="sales_lookup_value" value="<?= htmlspecialchars($salesLookupValue) ?>">
                        <input type="hidden" name="sales_lookup_date_from" value="<?= htmlspecialchars($salesLookupDateFrom) ?>">
                        <input type="hidden" name="sales_lookup_date_to" value="<?= htmlspecialchars($salesLookupDateTo) ?>">
                        <input type="hidden" name="sales_lookup_page" value="<?= htmlspecialchars((string) ($salesCurPage + 1)) ?>">
                        <button type="submit" style="margin-top:0;">Proxima pagina</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <p>
            <button type="button" id="mp-sales-lookup-json-btn" data-json-b64="<?= htmlspecialchars($salesJsonB64, ENT_QUOTES, 'UTF-8') ?>">Ver JSON da consulta</button>
        </p>
        <div style="overflow-x:auto;">
            <table>
                <thead>
                <tr>
                    <th>id</th>
                    <th>file_name</th>
                    <th>begin_date</th>
                    <th>end_date</th>
                    <th>status</th>
                    <th>date_created</th>
                    <th>account_id</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($salesItems)): ?>
                    <tr><td colspan="7">Nenhum resultado para os filtros informados.</td></tr>
                <?php else: ?>
                    <?php foreach ($salesItems as $it): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($it['id'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['file_name'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['begin_date'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['end_date'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['status'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['date_created'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) ($it['account_id'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    var form = document.getElementById('repasse-mp-upload-form');
    var btn = document.getElementById('repasse-mp-submit-btn');
    var overlay = document.getElementById('mp-processing-overlay');
    var lookupMode = document.getElementById('mp-lookup-mode');
    var lookupValueBlock = document.getElementById('mp-lookup-value-block');
    var salesLookupMode = document.getElementById('mp-sales-lookup-mode');
    var salesLookupValueBlock = document.getElementById('mp-sales-lookup-value-block');
    if (!form || !btn || !overlay) return;

    var processing = false;
    form.addEventListener('submit', function () {
        if (processing) return;
        processing = true;
        btn.disabled = true;
        btn.textContent = 'Processando...';
        overlay.classList.add('is-open');
    });

    window.addEventListener('beforeunload', function () {
        // Ao sair/F5 durante o POST, o navegador encerra a conexao.
        // O backend detecta isso e cancela o processamento.
    });

    if (lookupMode && lookupValueBlock) {
        function syncLookup() {
            lookupValueBlock.style.display = lookupMode.value === 'data' ? 'none' : 'block';
        }
        lookupMode.addEventListener('change', syncLookup);
        syncLookup();
    }

    if (salesLookupMode && salesLookupValueBlock) {
        function syncSalesLookup() {
            salesLookupValueBlock.style.display = salesLookupMode.value === 'data' ? 'none' : 'block';
        }
        salesLookupMode.addEventListener('change', syncSalesLookup);
        syncSalesLookup();
    }

    var lookupJsonBtn = document.getElementById('mp-lookup-json-btn');
    if (lookupJsonBtn) {
        var modal = document.createElement('div');
        modal.style.display = 'none';
        modal.style.position = 'fixed';
        modal.style.inset = '0';
        modal.style.background = 'rgba(0,0,0,.55)';
        modal.style.zIndex = '4000';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        modal.innerHTML = '<div style="background:#fff;max-width:960px;width:95%;max-height:88vh;border-radius:8px;display:flex;flex-direction:column;">'
            + '<div style="padding:10px 14px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">'
            + '<strong>JSON da consulta avulsa</strong>'
            + '<button type="button" id="mp-lookup-json-close" style="margin-top:0;background:#64748b;">Fechar</button>'
            + '</div>'
            + '<pre id="mp-lookup-json-pre" style="margin:0;padding:14px;background:#111827;color:#e5e7eb;overflow:auto;max-height:80vh;font-size:12px;line-height:1.45;"></pre>'
            + '</div>';
        document.body.appendChild(modal);

        function closeModal() {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }

        lookupJsonBtn.addEventListener('click', function () {
            var b64 = lookupJsonBtn.getAttribute('data-json-b64') || '';
            var text = '';
            try {
                text = atob(b64);
            } catch (e) {
                text = 'Falha ao decodificar JSON: ' + (e && e.message ? e.message : e);
            }
            var pre = modal.querySelector('#mp-lookup-json-pre');
            if (pre) pre.textContent = text;
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        });

        modal.addEventListener('click', function (ev) {
            if (ev.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && modal.style.display === 'flex') closeModal();
        });
        var closeBtn = modal.querySelector('#mp-lookup-json-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }
    }

    var salesLookupJsonBtn = document.getElementById('mp-sales-lookup-json-btn');
    if (salesLookupJsonBtn) {
        var salesModal = document.createElement('div');
        salesModal.style.display = 'none';
        salesModal.style.position = 'fixed';
        salesModal.style.inset = '0';
        salesModal.style.background = 'rgba(0,0,0,.55)';
        salesModal.style.zIndex = '4000';
        salesModal.style.alignItems = 'center';
        salesModal.style.justifyContent = 'center';
        salesModal.innerHTML = '<div style="background:#fff;max-width:960px;width:95%;max-height:88vh;border-radius:8px;display:flex;flex-direction:column;">'
            + '<div style="padding:10px 14px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">'
            + '<strong>JSON da consulta - Relatorio de vendas</strong>'
            + '<button type="button" id="mp-sales-lookup-json-close" style="margin-top:0;background:#64748b;">Fechar</button>'
            + '</div>'
            + '<pre id="mp-sales-lookup-json-pre" style="margin:0;padding:14px;background:#111827;color:#e5e7eb;overflow:auto;max-height:80vh;font-size:12px;line-height:1.45;"></pre>'
            + '</div>';
        document.body.appendChild(salesModal);

        function closeSalesModal() {
            salesModal.style.display = 'none';
            document.body.style.overflow = '';
        }

        salesLookupJsonBtn.addEventListener('click', function () {
            var b64 = salesLookupJsonBtn.getAttribute('data-json-b64') || '';
            var text = '';
            try {
                text = atob(b64);
            } catch (e) {
                text = 'Falha ao decodificar JSON: ' + (e && e.message ? e.message : e);
            }
            var pre = salesModal.querySelector('#mp-sales-lookup-json-pre');
            if (pre) pre.textContent = text;
            salesModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        });

        salesModal.addEventListener('click', function (ev) {
            if (ev.target === salesModal) closeSalesModal();
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && salesModal.style.display === 'flex') closeSalesModal();
        });
        var closeBtn2 = salesModal.querySelector('#mp-sales-lookup-json-close');
        if (closeBtn2) {
            closeBtn2.addEventListener('click', closeSalesModal);
        }
    }
})();
</script>
