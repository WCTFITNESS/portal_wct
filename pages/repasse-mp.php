<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
if (isset($_GET['flash_err']) && trim((string) $_GET['flash_err']) !== '') {
    $feedback = trim((string) $_GET['flash_err']);
    $feedbackClass = 'err';
}
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

$repasseJobId = '';
if (isset($_GET['job'])) {
    $cand = (string) $_GET['job'];
    if (strlen($cand) === 32 && ctype_xdigit($cand)) {
        $repasseJobId = $cand;
    }
}
?>
<section class="card">
    <h1>Repasse MP</h1>
    <p>Upload de planilha para buscar <code>order.id</code> no Mercado Pago. O sistema usa a <strong>coluna D</strong> como filtro e deduplica os valores para evitar chamadas repetidas na API.</p>
    <p>Mesmo com deduplicacao, todas as linhas originais sao mantidas no Excel final; linhas duplicadas recebem o mesmo <code>order</code> encontrado.</p>
    <p style="font-size:.9rem;color:#555;">Planilhas grandes sao consultadas ao Mercado Pago em <strong>varias etapas</strong> (requisicoes curtas), para evitar timeout do servidor (502) em hospedagens como Render.</p>

    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <p style="margin-top:8px;font-size:.9rem;color:#555;">
        <?= htmlspecialchars($tokenPreview) ?>
        Configure/edite em <strong>Configuração API</strong>.
    </p>
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
        .mp-progress-wrap {
            margin-top: 12px;
            width: 100%;
            max-width: 360px;
            background: #e5e7eb;
            border-radius: 6px;
            height: 10px;
            overflow: hidden;
        }
        .mp-progress-bar-inner {
            height: 100%;
            width: 0%;
            background: #d50000;
            transition: width .25s ease;
        }
        .mp-timer {
            margin-top: 10px;
            font-family: Consolas, monospace;
            font-size: 13px;
            color: #374151;
            font-weight: 600;
        }
    </style>
    <div id="repasse-mp-config" data-path-prefix="<?= htmlspecialchars(trim($baseUrl, '/')) ?>" data-job="<?= htmlspecialchars($repasseJobId) ?>" hidden></div>

    <form method="post" enctype="multipart/form-data" id="repasse-mp-upload-form">
        <input type="hidden" name="form_type" value="mp_upload">
        <label>Arquivo de repasse MP</label>
        <input type="file" name="repasse_mp_file" accept=".xls,.xlsx,.csv,.XLS,.XLSX,.CSV" required>
        <button type="submit" id="repasse-mp-submit-btn">Processar arquivo</button>
    </form>
    <div id="mp-processing-overlay" class="mp-processing-overlay" aria-live="polite" aria-busy="true">
        <div class="mp-processing-box">
            <span id="mp-processing-title">Processando arquivo, aguarde...</span>
            <div class="mp-progress-wrap" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                <div id="mp-progress-bar" class="mp-progress-bar-inner"></div>
            </div>
            <small id="mp-processing-detail">Preparando...</small>
            <div id="mp-processing-timer" class="mp-timer">Tempo: 00:00:00</div>
        </div>
    </div>

    <div id="repasse-mp-summary-block" style="display:none;margin-top:14px;" aria-live="polite"></div>
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

<section id="repasse-mp-preview-card" class="card" style="display:none;">
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
        <tbody id="repasse-mp-preview-tbody">
        </tbody>
    </table>
</section>

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
    var cfg = document.getElementById('repasse-mp-config');
    var asyncJobId = cfg && cfg.getAttribute('data-job') ? cfg.getAttribute('data-job') : '';
    var pathPrefix = cfg && cfg.getAttribute('data-path-prefix') ? cfg.getAttribute('data-path-prefix') : '';

    /** Evita //index.php quando o site esta na raiz (ERR_NAME_NOT_RESOLVED). */
    function portalIndexUrl(query) {
        var q = query.replace(/^\//, '');
        return pathPrefix === '' ? '/' + q : '/' + pathPrefix + '/' + q;
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    /** Dispara download sem sair da pagina (servidor envia Content-Disposition: attachment). */
    function triggerRepasseMpAutoDownload(url) {
        if (!url) return;
        window.setTimeout(function () {
            try {
                var ifr = document.createElement('iframe');
                ifr.setAttribute('aria-hidden', 'true');
                ifr.title = '';
                ifr.style.cssText = 'position:absolute;width:1px;height:1px;left:-100px;top:-100px;border:0;visibility:hidden';
                ifr.src = url;
                document.body.appendChild(ifr);
                window.setTimeout(function () {
                    try {
                        document.body.removeChild(ifr);
                    } catch (e2) {}
                }, 180000);
            } catch (e) {}
        }, 150);
    }

    function showRepasseAsyncError(msg) {
        var block = document.getElementById('repasse-mp-summary-block');
        if (!block) return;
        var text = String(msg || '');
        var extra = '';
        if (text.toLowerCase().indexOf('job nao encontrado') !== -1 || text.toLowerCase().indexOf('job não encontrado') !== -1) {
            extra = '<p style="margin-top:8px;">O estado agora fica salvo no banco para reduzir esse erro. Reenvie o arquivo e, se persistir, verifique conexão com o banco e logs do deploy.</p>';
        }
        block.style.display = 'block';
        block.innerHTML = '<div class="msg err">' + escHtml(text) + extra + '</div>';
    }

    function renderRepasseAsyncSummary(result) {
        var block = document.getElementById('repasse-mp-summary-block');
        if (!block || !result) return;
        var fn = result.file_name || '';
        var q = 'page=repasse-mp&download=' + encodeURIComponent(fn) + '&job=' + encodeURIComponent(asyncJobId);
        var downloadUrl = portalIndexUrl('index.php?' + q);
        block.style.display = 'block';
        block.innerHTML = '<p class="msg ok">Arquivo gerado. O download deve comecar em instantes. Se o navegador bloquear, use o link abaixo.</p>'
            + '<p>Processadas: <strong>' + escHtml(String(result.processed)) + '</strong> | '
            + 'Operacoes unicas (coluna D): <strong>' + escHtml(String(result.unique_operations)) + '</strong> | '
            + 'Coluna detectada: <strong>' + escHtml(String((parseInt(result.operation_column_index, 10) || 0) + 1)) + '</strong> | '
            + 'Nome da coluna detectada: <strong>' + escHtml(String(result.operation_column_name || '')) + '</strong> | '
            + 'Encontradas: <strong>' + escHtml(String(result.found)) + '</strong> | '
            + 'Nao encontradas: <strong>' + escHtml(String(result.not_found)) + '</strong> | '
            + 'Erros: <strong>' + escHtml(String(result.errors)) + '</strong> | '
            + 'Chamadas API: <strong>' + escHtml(String(result.api_calls)) + '</strong></p>'
            + '<p><a href="' + escHtml(downloadUrl) + '">Baixar novamente</a></p>';

        triggerRepasseMpAutoDownload(downloadUrl);

        var prevCard = document.getElementById('repasse-mp-preview-card');
        var tbody = document.getElementById('repasse-mp-preview-tbody');
        if (prevCard && tbody && result.preview && result.preview.length) {
            prevCard.style.display = 'block';
            tbody.innerHTML = '';
            result.preview.forEach(function (item) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + escHtml(String(item.linha)) + '</td>'
                    + '<td>' + escHtml(String(item.coluna_d)) + '</td>'
                    + '<td>' + escHtml(String(item.order)) + '</td>'
                    + '<td>' + escHtml(String(item.payment_id)) + '</td>'
                    + '<td>' + escHtml(String(item.duplicadas)) + '</td>'
                    + '<td>' + escHtml(String(item.status_consulta)) + '</td>';
                tbody.appendChild(tr);
            });
        }
    }

    async function runRepasseAsyncJob() {
        if (!asyncJobId || !cfg) return;
        var overlay = document.getElementById('mp-processing-overlay');
        var titleEl = document.getElementById('mp-processing-title');
        var detailEl = document.getElementById('mp-processing-detail');
        var bar = document.getElementById('mp-progress-bar');
        var timerEl = document.getElementById('mp-processing-timer');
        var startedAt = Date.now();
        var phaseStartedAt = Date.now();
        var phaseName = 'Consultando Mercado Pago';
        function fmtHms(sec) {
            var h = Math.floor(sec / 3600);
            var m = Math.floor((sec % 3600) / 60);
            var s = sec % 60;
            var hh = h < 10 ? '0' + h : String(h);
            var mm = m < 10 ? '0' + m : String(m);
            var ss = s < 10 ? '0' + s : String(s);
            return hh + ':' + mm + ':' + ss;
        }
        var timerId = window.setInterval(function () {
            if (!timerEl) return;
            var totalSec = Math.floor((Date.now() - startedAt) / 1000);
            var phaseSec = Math.floor((Date.now() - phaseStartedAt) / 1000);
            timerEl.textContent = 'Tempo total: ' + fmtHms(totalSec) + ' | Fase (' + phaseName + '): ' + fmtHms(phaseSec);
        }, 1000);
        if (overlay) overlay.classList.add('is-open');
        if (titleEl) titleEl.textContent = 'Consultando Mercado Pago...';
        var chunkUrl = portalIndexUrl('index.php?page=repasse-mp&repasse_action=chunk');
        var finalizeUrl = portalIndexUrl('index.php?page=repasse-mp&repasse_action=finalize');
        var statusUrl = portalIndexUrl('index.php?page=repasse-mp&repasse_action=status&job=' + encodeURIComponent(asyncJobId));

        async function sleepMs(ms) {
            return new Promise(function (resolve) { setTimeout(resolve, ms); });
        }

        async function waitForFinalizeCompletion() {
            for (var i = 0; i < 180; i++) {
                await sleepMs(5000);
                var stRes = await fetch(statusUrl + '&_=' + Date.now(), { method: 'GET' });
                var stRaw = await stRes.text();
                var st;
                try {
                    st = JSON.parse(stRaw);
                } catch (e) {
                    continue;
                }
                if (!st.ok) {
                    throw new Error(st.error || 'Falha ao acompanhar finalizacao.');
                }
                if (detailEl) {
                    if (st.status === 'finalizing') {
                        detailEl.textContent = 'Finalizando arquivo no servidor... aguarde.';
                    } else if (st.status === 'matching_done') {
                        detailEl.textContent = 'Aguardando inicio/retentativa da finalizacao...';
                    }
                }
                if (st.status === 'complete' && st.result) {
                    return st.result;
                }
                if (st.status === 'matching_done' && i % 8 === 0) {
                    try {
                        var retry = await fetch(finalizeUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'job=' + encodeURIComponent(asyncJobId)
                        });
                        var retryRaw = await retry.text();
                        var retryJson = JSON.parse(retryRaw);
                        if (retryJson.ok && retryJson.result) {
                            return retryJson.result;
                        }
                    } catch (e2) {
                        // continua no polling
                    }
                }
            }
            return null;
        }
        try {
            while (true) {
                var res = await fetch(chunkUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'job=' + encodeURIComponent(asyncJobId)
                });
                var raw = await res.text();
                var j;
                try {
                    j = JSON.parse(raw);
                } catch (e) {
                    throw new Error('Resposta invalida do servidor (502/timeout?). Tente novamente ou reduza o arquivo.');
                }
                if (!j.ok) {
                    throw new Error(j.error || 'Falha no lote.');
                }
                if (bar && typeof j.progress === 'number') {
                    bar.style.width = Math.min(100, j.progress) + '%';
                }
                if (detailEl) {
                    var cur = j.cursor !== undefined ? j.cursor : '';
                    var tot = j.total !== undefined ? j.total : '';
                    var extras = j.api_calls_total != null ? ' | Chamadas API acumuladas: ' + j.api_calls_total : '';
                    detailEl.textContent = 'Operacoes unicas consultadas: ' + cur + ' / ' + tot + extras;
                }
                if (j.phase === 'matching_done' || j.phase === 'complete') {
                    break;
                }
            }
            if (titleEl) titleEl.textContent = 'Gerando planilha final...';
            phaseName = 'Gerando planilha final';
            phaseStartedAt = Date.now();
            if (detailEl) detailEl.textContent = 'Montando a coluna order e gravando o XLSX (pode levar alguns minutos em arquivos muito grandes).';
            if (bar) bar.style.width = '100%';
            var fr = await fetch(finalizeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'job=' + encodeURIComponent(asyncJobId)
            });
            var rawF = await fr.text();
            var fj;
            try {
                fj = JSON.parse(rawF);
            } catch (e2) {
                if (detailEl) {
                    detailEl.textContent = 'Resposta de finalize expirou; verificando status no servidor...';
                }
                var recovered = await waitForFinalizeCompletion();
                if (recovered) {
                    if (overlay) overlay.classList.remove('is-open');
                    window.clearInterval(timerId);
                    renderRepasseAsyncSummary(recovered);
                    return;
                }
                throw new Error('Finalize levou tempo demais para responder. O servidor pode continuar processando; recarregue em alguns minutos com o mesmo ?job= na URL.');
            }
            if (!fj.ok) {
                throw new Error(fj.error || 'Falha ao finalizar.');
            }
            if (overlay) overlay.classList.remove('is-open');
            window.clearInterval(timerId);
            renderRepasseAsyncSummary(fj.result);
        } catch (e) {
            if (overlay) overlay.classList.remove('is-open');
            window.clearInterval(timerId);
            showRepasseAsyncError(e.message || e);
        }
    }

    var form = document.getElementById('repasse-mp-upload-form');
    var btn = document.getElementById('repasse-mp-submit-btn');
    var overlay = document.getElementById('mp-processing-overlay');
    var lookupMode = document.getElementById('mp-lookup-mode');
    var lookupValueBlock = document.getElementById('mp-lookup-value-block');
    var salesLookupMode = document.getElementById('mp-sales-lookup-mode');
    var salesLookupValueBlock = document.getElementById('mp-sales-lookup-value-block');

    var processing = false;
    if (form && btn && overlay) form.addEventListener('submit', function () {
        if (processing) return;
        processing = true;
        btn.disabled = true;
        btn.textContent = 'Processando...';
        overlay.classList.add('is-open');
    });

    if (asyncJobId && cfg) {
        runRepasseAsyncJob();
    }

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
