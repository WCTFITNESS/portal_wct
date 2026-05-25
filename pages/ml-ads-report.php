<?php

declare(strict_types=1);

function ml_ads_h(mixed $value): string
{
    $text = (string) $value;
    if ($text === '') {
        return '';
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        $text = is_string($clean) ? $clean : $text;
    }

    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ml_ads_row_class(string $tipoRow): string
{
    if ($tipoRow === 'Premium') {
        return 'row-tipo-premium';
    }
    if ($tipoRow === 'Classico') {
        return 'row-tipo-classico';
    }

    return '';
}

$feedback = null;
$feedbackClass = 'ok';
$summary = null;
$previewRows = [];
$previewLimitOnScreen = 500;

$limitRaw = trim((string) ($_REQUEST['limit'] ?? '200'));
$limit = (int) $limitRaw;
if ($limit < 0) {
    $limit = 0;
}
if ($limit > 5000) {
    $limit = 5000;
}
$dateFrom = trim((string) ($_REQUEST['date_from'] ?? ''));
$dateTo = trim((string) ($_REQUEST['date_to'] ?? ''));
$sku = trim((string) ($_REQUEST['sku'] ?? ''));
$tipo = trim((string) ($_REQUEST['tipo'] ?? 'todos'));

$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'sku' => $sku,
    'tipo' => $tipo,
];

$isPreviewRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string) ($_POST['form_type'] ?? '') === 'ml_ads_preview';
$isGetPreviewRequest = (($_GET['run'] ?? '') === '1');

if ($isPreviewRequest || $isGetPreviewRequest) {
    try {
        $result = $app['mlAdsReportService']->collectReportRows($limit, $filters);
        $summary = [
            'total_ids' => $result['total_ids'],
            'matched_rows' => $result['matched_rows'],
            'count_by_tipo' => $result['count_by_tipo'],
        ];
        $previewRows = is_array($result['preview'] ?? null) ? $result['preview'] : [];
        $rowCount = count($previewRows);
        $feedback = $rowCount > 0
            ? 'Consulta realizada.'
            : 'Nenhum anuncio encontrado com os filtros informados.';
        if ($rowCount === 0) {
            $feedbackClass = 'err';
        }
    } catch (Throwable $e) {
        $feedback = 'Erro: ' . $e->getMessage();
        $feedbackClass = 'err';
    }
}

function ml_ads_report_query(array $overrides = []): string
{
    global $baseUrl, $limit, $dateFrom, $dateTo, $sku, $tipo;

    $params = array_merge([
        'page' => 'ml-ads-report',
        'limit' => (string) $limit,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'sku' => $sku,
        'tipo' => $tipo,
    ], $overrides);

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }

    return portal_wct_public_path($baseUrl, 'index.php?' . http_build_query($params));
}

$previewOnScreen = array_slice($previewRows, 0, $previewLimitOnScreen);
$previewTotal = count($previewRows);
$canExport = is_array($summary) && $previewTotal > 0;
?>
<section class="card protheus-monitor-card">
    <h1>Relatorio de Anuncios ML</h1>
    <p>Lista anuncios da conta vendedora no Mercado Livre. Filtre, visualize na tabela e exporte para Excel.</p>
    <p style="font-size:.85rem;color:#64748b;margin:0 0 8px;">
        A tela mostra os principais campos; o Excel traz o relatorio completo (datas, envio, dimensoes, tags, marca, garantia, etc.).
    </p>

    <div class="protheus-legend">
        <span class="legend-item legend-tipo-premium">Premium (gold_pro)</span>
        <span class="legend-item legend-tipo-classico">Classico (gold_special, silver…)</span>
    </div>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= ml_ads_h($feedbackClass) ?>"><?= ml_ads_h($feedback) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= ml_ads_h(ml_ads_report_query()) ?>" class="protheus-filters" id="ml-ads-filter-form">
        <input type="hidden" name="form_type" value="ml_ads_preview">
        <div class="filter-grid">
            <label>Limite (0 = todos)
                <input type="number" name="limit" min="0" max="5000" value="<?= ml_ads_h((string) $limit) ?>">
            </label>
            <label>Data inicial
                <input type="date" name="date_from" value="<?= ml_ads_h($dateFrom) ?>">
            </label>
            <label>Data final
                <input type="date" name="date_to" value="<?= ml_ads_h($dateTo) ?>">
            </label>
            <label>SKU (contém)
                <input type="text" name="sku" value="<?= ml_ads_h($sku) ?>" placeholder="Ex.: 10100052">
            </label>
            <label>Tipo
                <select name="tipo">
                    <option value="todos" <?= $tipo === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="premium" <?= $tipo === 'premium' ? 'selected' : '' ?>>Premium</option>
                    <option value="classico" <?= $tipo === 'classico' ? 'selected' : '' ?>>Classico</option>
                </select>
            </label>
            <label class="filter-actions-label">&nbsp;
                <button type="submit">Filtrar</button>
            </label>
        </div>
    </form>

    <?php if (is_array($summary)): ?>
        <div class="protheus-summary-row">
            <p class="protheus-summary">
                Limite: <strong><?= $limit === 0 ? 'Todos' : ml_ads_h((string) $limit) ?></strong>
                | IDs consultados: <strong><?= (int) ($summary['total_ids'] ?? 0) ?></strong>
                | Exibidos: <strong><?= (int) ($summary['matched_rows'] ?? $previewTotal) ?></strong>
                <?php if ($previewTotal > count($previewOnScreen)): ?>
                    | Na tela: <strong><?= count($previewOnScreen) ?></strong> de <?= $previewTotal ?>
                <?php endif; ?>
                <?php if (!empty($summary['count_by_tipo']) && is_array($summary['count_by_tipo'])): ?>
                    | Premium: <strong><?= (int) ($summary['count_by_tipo']['Premium'] ?? 0) ?></strong>
                    | Classico: <strong><?= (int) ($summary['count_by_tipo']['Classico'] ?? 0) ?></strong>
                    <?php if (($summary['count_by_tipo']['Outros'] ?? 0) > 0): ?>
                        | Outros: <strong><?= (int) $summary['count_by_tipo']['Outros'] ?></strong>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
            <?php if ($canExport): ?>
                <a
                    class="btn-export-xlsx"
                    href="<?= ml_ads_h(ml_ads_report_query(['export' => 'xlsx'])) ?>"
                >Exportar Excel</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($previewTotal > 0): ?>
        <?php if ($previewTotal > count($previewOnScreen)): ?>
            <p class="protheus-summary" style="margin:8px 0 4px;">
                Mostrando os primeiros <?= count($previewOnScreen) ?> anuncios na tela.
                Use <strong>Exportar Excel</strong> para obter a lista completa (<?= $previewTotal ?>).
            </p>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="protheus-table">
                <thead>
                    <tr>
                        <th>MLB</th>
                        <th>Titulo</th>
                        <th>Criacao</th>
                        <th>Atualizacao</th>
                        <th>Inicio</th>
                        <th>Status</th>
                        <th>Condicao</th>
                        <th>SKU</th>
                        <th>Tipo</th>
                        <th>Preco De</th>
                        <th>Preco Por</th>
                        <th>Categoria</th>
                        <th>Modo compra</th>
                        <th>Full</th>
                        <th>Frete gratis</th>
                        <th>Estoque</th>
                        <th>Vendas</th>
                        <th>Variacoes</th>
                        <th>Marca</th>
                        <th>Link</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewOnScreen as $row): ?>
                        <?php
                        $tipoRow = (string) ($row['tipo'] ?? '');
                        $rowClass = ml_ads_row_class($tipoRow);
                        $permalink = trim((string) ($row['permalink'] ?? ''));
                        ?>
                        <tr class="<?= ml_ads_h($rowClass) ?>">
                            <td><?= ml_ads_h($row['mlb'] ?? '') ?></td>
                            <td class="cell-titulo" title="<?= ml_ads_h($row['titulo'] ?? '') ?>">
                                <?= ml_ads_h($row['titulo'] ?? '') ?>
                            </td>
                            <td class="cell-nowrap"><?= ml_ads_h($row['data_criacao'] ?? '') ?></td>
                            <td class="cell-nowrap"><?= ml_ads_h($row['data_atualizacao'] ?? '') ?></td>
                            <td class="cell-nowrap"><?= ml_ads_h($row['data_inicio'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['status'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['condicao'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['sku'] ?? '') ?></td>
                            <td><?= ml_ads_h($tipoRow) ?></td>
                            <td><?= ml_ads_h($row['preco_de'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['preco_por'] ?? '') ?></td>
                            <td><code class="cell-code"><?= ml_ads_h($row['categoria_id'] ?? '') ?></code></td>
                            <td><?= ml_ads_h($row['modo_compra'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['full'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['frete_gratis'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['estoque'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['vendas'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['variacoes'] ?? '') ?></td>
                            <td><?= ml_ads_h($row['marca'] ?? '') ?></td>
                            <td class="cell-link">
                                <?php if ($permalink !== ''): ?>
                                    <button
                                        type="button"
                                        class="ml-ads-open-link"
                                        data-url="<?= ml_ads_h($permalink) ?>"
                                        data-mlb="<?= ml_ads_h($row['mlb'] ?? '') ?>"
                                        data-ajustado="<?= ml_ads_h($row['permalink_ajustado'] ?? 'nao') ?>"
                                        data-original="<?= ml_ads_h($row['permalink_original'] ?? '') ?>"
                                    >Abrir</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif (is_array($summary) && ($isPreviewRequest || $isGetPreviewRequest)): ?>
        <div class="table-wrap">
            <table class="protheus-table">
                <tbody>
                    <tr><td colspan="20">Nenhum registro com os filtros informados.</td></tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div id="ml-ads-link-modal" class="ml-ads-link-modal" hidden aria-hidden="true">
    <div class="ml-ads-link-modal-backdrop" data-ml-ads-link-close></div>
    <div class="ml-ads-link-modal-card" role="dialog" aria-labelledby="ml-ads-link-modal-title" aria-modal="true">
        <h2 id="ml-ads-link-modal-title">Link do anuncio</h2>
        <p id="ml-ads-link-modal-text" class="ml-ads-link-modal-text"></p>
        <p class="ml-ads-link-modal-meta"><strong id="ml-ads-link-modal-mlb"></strong></p>
        <div class="ml-ads-link-modal-actions">
            <button type="button" class="ml-ads-link-btn-primary" id="ml-ads-link-modal-open">Abrir pagina publica</button>
            <button type="button" class="ml-ads-link-btn-secondary" data-ml-ads-link-close>Fechar</button>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('ml-ads-link-modal');
    if (!modal) return;

    var textEl = document.getElementById('ml-ads-link-modal-text');
    var mlbEl = document.getElementById('ml-ads-link-modal-mlb');
    var openBtn = document.getElementById('ml-ads-link-modal-open');
    var pendingUrl = '';

    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        pendingUrl = '';
    }

    function openModal(message, url, mlb) {
        pendingUrl = url;
        textEl.textContent = message;
        mlbEl.textContent = mlb ? ('Anuncio: ' + mlb) : '';
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        openBtn.focus();
    }

    function openExternal(url) {
        if (!url) return;
        window.open(url, '_blank', 'noopener,noreferrer');
    }

    modal.querySelectorAll('[data-ml-ads-link-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    openBtn.addEventListener('click', function () {
        openExternal(pendingUrl);
        closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });

    document.querySelectorAll('.ml-ads-open-link').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = btn.getAttribute('data-url') || '';
            var mlb = btn.getAttribute('data-mlb') || '';
            var ajustado = (btn.getAttribute('data-ajustado') || '') === 'sim';
            var original = btn.getAttribute('data-original') || '';

            if (!url) {
                openModal('Nao ha link publico disponivel para este anuncio.', '', mlb);
                return;
            }

            if (ajustado) {
                var msg = 'A API do Mercado Livre retornou um endereco interno (nao abre no navegador). '
                    + 'O portal converteu para a pagina publica do anuncio.';
                if (original) {
                    msg += ' Link original ignorado: ' + original + '.';
                }
                openModal(msg, url, mlb);
                return;
            }

            openExternal(url);
        });
    });
})();
</script>

<style>
    .legend-tipo-premium { background: #fef9c3; color: #713f12; border: 1px solid #fde047; }
    .legend-tipo-classico { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
    tr.row-tipo-premium { background: #fffbeb; }
    tr.row-tipo-classico { background: #eff6ff; }
    .protheus-table .cell-titulo {
        max-width: 320px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .protheus-table .cell-code {
        font-size: .75rem;
        background: #f1f5f9;
        padding: 1px 4px;
        border-radius: 3px;
    }
    .protheus-table .cell-nowrap {
        white-space: nowrap;
        font-size: .72rem;
    }
    .protheus-table .cell-link .ml-ads-open-link {
        border: none;
        background: transparent;
        color: #1d4ed8;
        font-weight: bold;
        cursor: pointer;
        padding: 0;
        font-size: inherit;
        font-family: inherit;
        text-decoration: underline;
        white-space: nowrap;
    }
    .protheus-table .cell-link .ml-ads-open-link:hover {
        color: #1e3a8a;
    }
    .ml-ads-link-modal {
        position: fixed;
        inset: 0;
        z-index: 1200;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .ml-ads-link-modal[hidden] {
        display: none !important;
    }
    .ml-ads-link-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
    }
    .ml-ads-link-modal-card {
        position: relative;
        width: min(520px, 100%);
        background: #fff;
        border-radius: 10px;
        border: 1px solid var(--wct-border, #e5e7eb);
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18);
        padding: 18px 20px;
    }
    .ml-ads-link-modal-card h2 {
        margin: 0 0 10px;
        font-size: 1.1rem;
    }
    .ml-ads-link-modal-text {
        margin: 0 0 8px;
        font-size: .9rem;
        line-height: 1.45;
        color: #334155;
    }
    .ml-ads-link-modal-meta {
        margin: 0 0 14px;
        font-size: .82rem;
        color: #64748b;
    }
    .ml-ads-link-modal-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .ml-ads-link-btn-primary,
    .ml-ads-link-btn-secondary {
        border-radius: 6px;
        padding: 9px 14px;
        font-weight: bold;
        font-size: .78rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        cursor: pointer;
        font-family: inherit;
    }
    .ml-ads-link-btn-primary {
        border: 1px solid #f5b700;
        background: #111111;
        color: #f5b700;
    }
    .ml-ads-link-btn-primary:hover {
        background: #f5b700;
        color: #111111;
    }
    .ml-ads-link-btn-secondary {
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #475569;
    }
    .ml-ads-link-btn-secondary:hover {
        background: #f8fafc;
    }
</style>
