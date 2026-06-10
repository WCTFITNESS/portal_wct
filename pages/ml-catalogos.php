<?php

declare(strict_types=1);

function ml_cat_h(mixed $value): string
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

function ml_cat_row_class(string $status): string
{
    return match (strtolower($status)) {
        'active' => 'row-status-active',
        'paused' => 'row-status-paused',
        'closed' => 'row-status-closed',
        default => '',
    };
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
$status = trim((string) ($_REQUEST['status'] ?? 'todos'));
$sku = trim((string) ($_REQUEST['sku'] ?? ''));
$catalogProductId = trim((string) ($_REQUEST['catalog_product_id'] ?? ''));

$filters = [
    'status' => $status,
    'sku' => $sku,
    'catalog_product_id' => $catalogProductId,
];

$isPreviewRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string) ($_POST['form_type'] ?? '') === 'ml_catalog_preview';
$isGetPreviewRequest = (($_GET['run'] ?? '') === '1');

if ($isPreviewRequest || $isGetPreviewRequest) {
    try {
        $result = $app['mlCatalogListService']->collectCatalogRows($limit, $filters);
        $summary = [
            'total_ids' => $result['total_ids'],
            'matched_rows' => $result['matched_rows'],
            'count_by_status' => $result['count_by_status'],
        ];
        $previewRows = is_array($result['preview'] ?? null) ? $result['preview'] : [];
        $rowCount = count($previewRows);
        if ($rowCount > 0) {
            $feedback = 'Consulta realizada.';
        } elseif ((int) ($result['total_ids'] ?? 0) === 0) {
            $feedback = 'Nenhuma publicacao de catalogo encontrada para esta conta.';
            $feedbackClass = 'err';
        } else {
            $feedback = 'Nenhum catalogo corresponde aos filtros informados.';
            $feedbackClass = 'err';
        }
    } catch (Throwable $e) {
        $feedback = 'Erro: ' . $e->getMessage();
        $feedbackClass = 'err';
    }
}

function ml_catalog_query(array $overrides = []): string
{
    global $baseUrl, $limit, $status, $sku, $catalogProductId;

    $params = array_merge([
        'page' => 'ml-catalogos',
        'limit' => (string) $limit,
        'status' => $status,
        'sku' => $sku,
        'catalog_product_id' => $catalogProductId,
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
    <h1>Catalogos Mercado Livre</h1>
    <p>
        Lista as publicacoes de <strong>catalogo</strong> da conta vendedora
        (<code>catalog_listing=true</code> na API do Mercado Livre).
    </p>
    <p style="font-size:.85rem;color:#64748b;margin:0 0 8px;">
        Sao os anuncios publicados no modelo Catalogo (compra garantida), vinculados a um
        <em>catalog product id</em> (pagina de produto do ML).
        Clique no <strong>titulo</strong> para ver a ficha do produto e os vendedores concorrentes.
    </p>

    <div class="protheus-legend">
        <span class="legend-item legend-status-active">Ativo</span>
        <span class="legend-item legend-status-paused">Pausado</span>
        <span class="legend-item legend-status-closed">Encerrado</span>
    </div>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= ml_cat_h($feedbackClass) ?>"><?= ml_cat_h($feedback) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= ml_cat_h(ml_catalog_query()) ?>" class="protheus-filters" id="ml-catalog-filter-form">
        <input type="hidden" name="form_type" value="ml_catalog_preview">
        <div class="filter-grid">
            <label>Limite (0 = todos)
                <input type="number" name="limit" min="0" max="5000" value="<?= ml_cat_h((string) $limit) ?>">
            </label>
            <label>Status
                <select name="status">
                    <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativo</option>
                    <option value="paused" <?= $status === 'paused' ? 'selected' : '' ?>>Pausado</option>
                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Encerrado</option>
                </select>
            </label>
            <label>SKU (contem)
                <input type="text" name="sku" value="<?= ml_cat_h($sku) ?>" placeholder="Ex.: 10100052">
            </label>
            <label>Catalog Product ID (contem)
                <input type="text" name="catalog_product_id" value="<?= ml_cat_h($catalogProductId) ?>" placeholder="Ex.: MLB1234567890">
            </label>
            <label class="filter-actions-label">&nbsp;
                <button type="submit">Filtrar</button>
            </label>
        </div>
    </form>

    <?php if (is_array($summary)): ?>
        <div class="protheus-summary-row">
            <p class="protheus-summary">
                Limite: <strong><?= $limit === 0 ? 'Todos' : ml_cat_h((string) $limit) ?></strong>
                | IDs consultados: <strong><?= (int) ($summary['total_ids'] ?? 0) ?></strong>
                | Exibidos: <strong><?= (int) ($summary['matched_rows'] ?? $previewTotal) ?></strong>
                <?php if ($previewTotal > count($previewOnScreen)): ?>
                    | Na tela: <strong><?= count($previewOnScreen) ?></strong> de <?= $previewTotal ?>
                <?php endif; ?>
                <?php if (!empty($summary['count_by_status']) && is_array($summary['count_by_status'])): ?>
                    <?php foreach ($summary['count_by_status'] as $st => $cnt): ?>
                        | <?= ml_cat_h((string) $st) ?>: <strong><?= (int) $cnt ?></strong>
                    <?php endforeach; ?>
                <?php endif; ?>
            </p>
            <?php if ($canExport): ?>
                <a
                    class="btn-export-xlsx"
                    href="<?= ml_cat_h(ml_catalog_query(['export' => 'xlsx'])) ?>"
                >Exportar Excel</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($previewTotal > 0): ?>
        <?php if ($previewTotal > count($previewOnScreen)): ?>
            <p class="protheus-summary" style="margin:8px 0 4px;">
                Mostrando os primeiros <?= count($previewOnScreen) ?> catalogos na tela.
                Use <strong>Exportar Excel</strong> para a lista completa (<?= $previewTotal ?>).
            </p>
        <?php endif; ?>

        <div class="table-wrap">
            <table class="protheus-table">
                <thead>
                    <tr>
                        <th>MLB</th>
                        <th>Catalog Product ID</th>
                        <th>Titulo</th>
                        <th>Status</th>
                        <th>SKU</th>
                        <th>Preco</th>
                        <th>Estoque</th>
                        <th>Vendidos</th>
                        <th>Categoria</th>
                        <th>Criacao</th>
                        <th>Atualizacao</th>
                        <th>Tags</th>
                        <th>Link</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewOnScreen as $row): ?>
                        <?php
                        $statusRow = (string) ($row['status'] ?? '');
                        $rowClass = ml_cat_row_class($statusRow);
                        $permalink = trim((string) ($row['permalink'] ?? ''));
                        ?>
                        <tr class="<?= ml_cat_h($rowClass) ?>">
                            <td><code class="cell-code"><?= ml_cat_h($row['mlb'] ?? '') ?></code></td>
                            <td><code class="cell-code"><?= ml_cat_h($row['catalog_product_id'] ?? '') ?></code></td>
                            <td class="cell-titulo">
                                <?php
                                $catPid = trim((string) ($row['catalog_product_id'] ?? ''));
                                $titulo = (string) ($row['titulo'] ?? '');
                                ?>
                                <?php if ($catPid !== ''): ?>
                                    <button
                                        type="button"
                                        class="ml-cat-title-btn"
                                        title="Ver produto e vendedores concorrentes"
                                        data-catalog-product-id="<?= ml_cat_h($catPid) ?>"
                                        data-item-mlb="<?= ml_cat_h($row['mlb'] ?? '') ?>"
                                        data-titulo="<?= ml_cat_h($titulo) ?>"
                                    ><?= ml_cat_h($titulo) ?></button>
                                <?php else: ?>
                                    <span title="Sem Catalog Product ID"><?= ml_cat_h($titulo) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= ml_cat_h($statusRow) ?></td>
                            <td><?= ml_cat_h($row['sku'] ?? '') ?></td>
                            <td><?= ml_cat_h($row['preco'] ?? '') ?></td>
                            <td><?= ml_cat_h($row['estoque'] ?? '') ?></td>
                            <td><?= ml_cat_h($row['vendidos'] ?? '') ?></td>
                            <td><code class="cell-code"><?= ml_cat_h($row['categoria_id'] ?? '') ?></code></td>
                            <td class="cell-nowrap"><?= ml_cat_h($row['data_criacao'] ?? '') ?></td>
                            <td class="cell-nowrap"><?= ml_cat_h($row['data_atualizacao'] ?? '') ?></td>
                            <td class="cell-tags" title="<?= ml_cat_h($row['tags'] ?? '') ?>">
                                <?= ml_cat_h($row['tags'] ?? '') ?>
                            </td>
                            <td class="cell-link">
                                <?php if ($permalink !== ''): ?>
                                    <a href="<?= ml_cat_h($permalink) ?>" target="_blank" rel="noopener noreferrer">Abrir</a>
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
                    <tr><td colspan="13">Nenhum registro com os filtros informados.</td></tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div id="ml-cat-detail-modal" class="ml-cat-modal" hidden aria-hidden="true">
    <div class="ml-cat-modal-backdrop" data-ml-cat-close></div>
    <div class="ml-cat-modal-card" role="dialog" aria-labelledby="ml-cat-modal-title" aria-modal="true">
        <div class="ml-cat-modal-head">
            <h2 id="ml-cat-modal-title">Detalhe do catalogo</h2>
            <button type="button" class="ml-cat-modal-close" data-ml-cat-close aria-label="Fechar">&times;</button>
        </div>
        <div class="ml-cat-modal-body" id="ml-cat-modal-body">
            <p class="ml-cat-modal-loading">Carregando…</p>
        </div>
    </div>
</div>

<style>
    .protheus-monitor-card h1 { font-size: 1.25rem; margin-bottom: 8px; }
    .protheus-legend { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
    .legend-item {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 6px;
        font-size: .8rem;
        font-weight: bold;
    }
    .legend-status-active { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .legend-status-paused { background: #fef9c3; color: #713f12; border: 1px solid #fde047; }
    .legend-status-closed { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
    tr.row-status-active { background: #f0fdf4 !important; }
    tr.row-status-paused { background: #fefce8 !important; }
    tr.row-status-closed { background: #f8fafc !important; }
    .protheus-filters .filter-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px 14px;
        align-items: end;
    }
    .protheus-filters label { margin-top: 0; font-weight: bold; font-size: .85rem; }
    .protheus-filters input,
    .protheus-filters select { margin-top: 4px; width: 100%; }
    .filter-actions-label button { margin-top: 4px; width: 100%; }
    .protheus-summary-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 12px 0 6px;
    }
    .protheus-summary { margin: 0; color: var(--wct-muted); font-size: .88rem; }
    a.btn-export-xlsx {
        display: inline-block;
        padding: 9px 14px;
        border-radius: 6px;
        border: 1px solid #f5b700;
        background: #111111;
        color: #f5b700;
        font-weight: bold;
        font-size: .78rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        text-decoration: none;
        white-space: nowrap;
    }
    a.btn-export-xlsx:hover { background: #f5b700; color: #111111; }
    .table-wrap {
        width: 100%;
        overflow-x: auto;
        border: 1px solid var(--wct-border);
        border-radius: 6px;
        margin-top: 6px;
    }
    .protheus-table {
        width: max-content;
        min-width: 100%;
        border-collapse: collapse;
        font-size: .78rem;
    }
    .protheus-table th,
    .protheus-table td {
        padding: 5px 8px;
        border-bottom: 1px solid #e8edf5;
        text-align: left;
        vertical-align: top;
    }
    .protheus-table thead th {
        position: sticky;
        top: 0;
        background: #f1f5f9;
        white-space: nowrap;
        font-size: .75rem;
        text-transform: uppercase;
    }
    .cell-titulo { max-width: 280px; }
    button.ml-cat-title-btn {
        display: block;
        width: 100%;
        text-align: left;
        background: none;
        border: none;
        padding: 0;
        margin: 0;
        font: inherit;
        color: #1d4ed8;
        cursor: pointer;
        text-decoration: underline;
        text-underline-offset: 2px;
    }
    button.ml-cat-title-btn:hover { color: #1e3a8a; }
    .ml-cat-modal {
        position: fixed;
        inset: 0;
        z-index: 2200;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .ml-cat-modal[hidden] { display: none !important; }
    .ml-cat-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, .55);
    }
    .ml-cat-modal-card {
        position: relative;
        z-index: 1;
        width: min(960px, 100%);
        max-height: 90vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        background: #fff;
        border-radius: 8px;
        border: 1px solid var(--wct-border);
        box-shadow: 0 12px 40px rgba(0, 0, 0, .25);
    }
    .ml-cat-modal-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .ml-cat-modal-head h2 {
        margin: 0;
        font-size: 1.05rem;
        line-height: 1.35;
        padding-right: 8px;
    }
    .ml-cat-modal-close {
        border: none;
        background: transparent;
        font-size: 1.5rem;
        line-height: 1;
        cursor: pointer;
        color: #64748b;
        padding: 0 4px;
    }
    .ml-cat-modal-close:hover { color: #0f172a; }
    .ml-cat-modal-body {
        padding: 16px;
        overflow-y: auto;
        font-size: .88rem;
    }
    .ml-cat-modal-loading { color: #64748b; margin: 0; }
    .ml-cat-modal-err { color: #b91c1c; margin: 0; }
    .ml-cat-product-grid {
        display: grid;
        grid-template-columns: 120px 1fr;
        gap: 16px;
        margin-bottom: 18px;
    }
    .ml-cat-product-img {
        width: 120px;
        height: 120px;
        object-fit: contain;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        background: #fff;
    }
    .ml-cat-product-meta { margin: 0 0 6px; }
    .ml-cat-product-meta strong { color: #334155; }
    .ml-cat-attrs {
        margin: 10px 0 0;
        padding: 0;
        list-style: none;
        columns: 2;
        gap: 4px 16px;
    }
    .ml-cat-attrs li { margin-bottom: 4px; break-inside: avoid; }
    .ml-cat-section-title {
        margin: 18px 0 8px;
        font-size: .95rem;
        font-weight: bold;
    }
    .ml-cat-warning {
        margin: 0 0 12px;
        padding: 8px 10px;
        background: #fffbeb;
        border: 1px solid #fde047;
        border-radius: 6px;
        color: #713f12;
        font-size: .82rem;
    }
    .ml-cat-comp-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .78rem;
    }
    .ml-cat-comp-table th,
    .ml-cat-comp-table td {
        padding: 6px 8px;
        border-bottom: 1px solid #e8edf5;
        text-align: left;
        vertical-align: top;
    }
    .ml-cat-comp-table thead th {
        background: #f1f5f9;
        position: sticky;
        top: 0;
        z-index: 1;
    }
    tr.ml-cat-row-ours { background: #eff6ff !important; }
    tr.ml-cat-row-winner { background: #f0fdf4 !important; }
    .ml-cat-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: .7rem;
        font-weight: bold;
        margin-right: 4px;
    }
    .ml-cat-badge-ours { background: #dbeafe; color: #1e40af; }
    .ml-cat-badge-winner { background: #bbf7d0; color: #166534; }
    @media (max-width: 700px) {
        .ml-cat-product-grid { grid-template-columns: 1fr; }
        .ml-cat-attrs { columns: 1; }
    }
    .cell-tags { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cell-code { font-size: .72rem; }
    .cell-nowrap { white-space: nowrap; }
    .cell-link a { font-weight: bold; }
    @media (max-width: 1100px) {
        .protheus-filters .filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>

<script>
(function () {
    const apiBase = <?= json_encode(portal_wct_public_path($baseUrl, 'index.php?page=ml-catalogos'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
    const modal = document.getElementById('ml-cat-detail-modal');
    const modalBody = document.getElementById('ml-cat-modal-body');
    const modalTitle = document.getElementById('ml-cat-modal-title');

    if (!modal || !modalBody) return;

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text == null ? '' : String(text);
        return d.innerHTML;
    }

    function closeModal() {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function openModal() {
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    modal.querySelectorAll('[data-ml-cat-close]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && !modal.hidden) closeModal();
    });

    function renderProduct(product) {
        if (!product || typeof product !== 'object') return '';
        let html = '<div class="ml-cat-product-grid">';
        if (product.picture_url) {
            html += '<img class="ml-cat-product-img" src="' + escapeHtml(product.picture_url) + '" alt="">';
        } else {
            html += '<div class="ml-cat-product-img" style="display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:.75rem;">Sem foto</div>';
        }
        html += '<div>';
        html += '<p class="ml-cat-product-meta"><strong>Catalog Product ID:</strong> <code>' + escapeHtml(product.id) + '</code></p>';
        html += '<p class="ml-cat-product-meta"><strong>Status:</strong> ' + escapeHtml(product.status) + '</p>';
        html += '<p class="ml-cat-product-meta"><strong>Dominio:</strong> ' + escapeHtml(product.domain_id) + '</p>';
        html += '<p class="ml-cat-product-meta"><strong>Vendidos (pagina):</strong> ' + escapeHtml(product.sold_quantity) + '</p>';
        if (product.price_min || product.price_max) {
            html += '<p class="ml-cat-product-meta"><strong>Faixa de preco (buy box):</strong> '
                + escapeHtml(product.price_min) + ' — ' + escapeHtml(product.price_max)
                + (product.currency_id ? ' ' + escapeHtml(product.currency_id) : '') + '</p>';
        }
        const bb = product.buy_box_winner || {};
        if (bb.item_id) {
            html += '<p class="ml-cat-product-meta"><strong>Ganhador buy box:</strong> '
                + escapeHtml(bb.item_id) + ' · vendedor ' + escapeHtml(bb.seller_id)
                + ' · R$ ' + escapeHtml(bb.price) + '</p>';
        }
        if (product.permalink) {
            html += '<p class="ml-cat-product-meta"><a href="' + escapeHtml(product.permalink) + '" target="_blank" rel="noopener noreferrer">Abrir pagina do produto no ML</a></p>';
        }
        if (product.short_description) {
            html += '<p class="ml-cat-product-meta" style="margin-top:8px;">' + escapeHtml(product.short_description) + '</p>';
        }
        html += '</div></div>';

        const attrs = product.attributes || [];
        if (attrs.length) {
            html += '<h3 class="ml-cat-section-title">Caracteristicas do produto</h3><ul class="ml-cat-attrs">';
            attrs.forEach(function (a) {
                html += '<li><strong>' + escapeHtml(a.name) + ':</strong> ' + escapeHtml(a.value) + '</li>';
            });
            html += '</ul>';
        }
        return html;
    }

    function renderCompetitors(competitors) {
        if (!competitors || !competitors.length) {
            return '<p class="ml-cat-product-meta">Nenhum vendedor concorrente retornado pela API.</p>';
        }
        let html = '<h3 class="ml-cat-section-title">Vendedores neste catalogo (' + competitors.length + ')</h3>';
        html += '<div style="overflow-x:auto;max-height:min(50vh,420px);border:1px solid #e2e8f0;border-radius:6px;">';
        html += '<table class="ml-cat-comp-table"><thead><tr>';
        html += '<th>Vendedor / empresa</th><th>MLB</th><th>Preco</th><th>Estoque</th><th>Vendidos</th><th>Envio</th><th>Tipo</th>';
        html += '</tr></thead><tbody>';
        competitors.forEach(function (c) {
            let rowClass = '';
            if (c.is_our_item || c.is_our_seller) rowClass = 'ml-cat-row-ours';
            else if (c.is_buy_box_winner) rowClass = 'ml-cat-row-winner';
            html += '<tr class="' + rowClass + '">';
            html += '<td>';
            if (c.is_our_seller || c.is_our_item) html += '<span class="ml-cat-badge ml-cat-badge-ours">Sua conta</span>';
            if (c.is_buy_box_winner) html += '<span class="ml-cat-badge ml-cat-badge-winner">Buy box</span>';
            html += '<div><strong>' + escapeHtml(c.seller_name) + '</strong></div>';
            if (c.seller_nickname && c.seller_company) {
                html += '<div style="color:#64748b;font-size:.75rem;">@' + escapeHtml(c.seller_nickname) + '</div>';
            }
            html += '<div style="color:#64748b;font-size:.75rem;">ID ' + escapeHtml(c.seller_id) + '</div>';
            if (c.official_store_id) {
                html += '<div style="color:#64748b;font-size:.75rem;">Loja oficial #' + escapeHtml(c.official_store_id) + '</div>';
            }
            html += '</td>';
            html += '<td><code>' + escapeHtml(c.item_id) + '</code></td>';
            html += '<td>' + escapeHtml(c.price) + '</td>';
            html += '<td>' + escapeHtml(c.available_quantity) + '</td>';
            html += '<td>' + escapeHtml(c.sold_quantity) + '</td>';
            html += '<td>' + escapeHtml(c.free_shipping === 'sim' ? 'Gratis' : 'Pago');
            if (c.logistic_type) html += '<br><span style="color:#64748b">' + escapeHtml(c.logistic_type) + '</span>';
            html += '</td>';
            html += '<td>' + escapeHtml(c.listing_type_id) + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        return html;
    }

    async function loadDetail(catalogProductId, itemMlb, titulo) {
        modalTitle.textContent = titulo || 'Detalhe do catalogo';
        modalBody.innerHTML = '<p class="ml-cat-modal-loading">Consultando Mercado Livre…</p>';
        openModal();

        try {
            const res = await fetch(apiBase + '&ml_catalog_action=detail', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    catalog_product_id: catalogProductId,
                    item_mlb: itemMlb,
                }),
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.error || ('HTTP ' + res.status));
            }

            let html = renderProduct(data.product);
            if (data.competitors_warning) {
                html = '<p class="ml-cat-warning">' + escapeHtml(data.competitors_warning) + '</p>' + html;
            }
            html += renderCompetitors(data.competitors || []);
            modalBody.innerHTML = html;
        } catch (err) {
            modalBody.innerHTML = '<p class="ml-cat-modal-err">Erro: ' + escapeHtml(err.message || String(err)) + '</p>';
        }
    }

    document.querySelectorAll('.ml-cat-title-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            loadDetail(
                btn.dataset.catalogProductId || '',
                btn.dataset.itemMlb || '',
                btn.dataset.titulo || ''
            );
        });
    });
})();
</script>
