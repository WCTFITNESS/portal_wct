<?php

declare(strict_types=1);

use App\Services\ProtheusLerEdiService;

$feedback = null;
$feedbackClass = 'ok';
$result = null;

$filial = trim((string) ($_GET['filial'] ?? '0101'));
$job = trim((string) ($_GET['job'] ?? ''));
$err = trim((string) ($_GET['err'] ?? ''));
$pageNum = max(1, (int) ($_GET['p'] ?? 1));
$perPage = max(25, min(200, (int) ($_GET['per_page'] ?? 100)));

$filterQ = trim((string) ($_GET['q'] ?? ''));
$filterNota = trim((string) ($_GET['nota_fiscal'] ?? ''));
$filterPedido = trim((string) ($_GET['pedido'] ?? ''));
$filterPedMar = trim((string) ($_GET['ped_mar'] ?? ''));
$filterMarketplace = trim((string) ($_GET['marketplace'] ?? ''));
$filterIdlexo = trim((string) ($_GET['idlexo'] ?? ''));
$filterCod = trim((string) ($_GET['cod_ocorrencia'] ?? ''));
$filterOcorrencia = trim((string) ($_GET['ocorrencia'] ?? ''));
$filterCte = trim((string) ($_GET['cte'] ?? ''));
$filterMatch = trim((string) ($_GET['match'] ?? ''));
$filterTexto = trim((string) ($_GET['texto'] ?? ''));

$lerEdiService = $app['protheusLerEdiService'];
$settings = $app['protheusSettingsRepository']->getSettings();
$canCross = $settings !== null && $app['protheusConnectionService']->isDriverAvailable();
$crossProtheus = true;

if ($err !== '') {
    $feedback = 'Erro: ' . $err;
    $feedbackClass = 'err';
}

if ($job !== '') {
    $loaded = $lerEdiService->loadResult($job);
    if ($loaded === null) {
        $feedback = 'Resultado do upload nao encontrado ou expirado. Envie o arquivo novamente.';
        $feedbackClass = 'err';
    } else {
        $result = $loaded;
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $feedback = sprintf(
            'Arquivo lido: %d ocorrencia(s). Cruzadas com Protheus: %d. Sem pedido: %d.',
            (int) ($summary['ocorrencias'] ?? 0),
            (int) ($summary['matched'] ?? 0),
            (int) ($summary['unmatched'] ?? 0)
        );
        $warning = trim((string) ($result['warning'] ?? ''));
        if ($warning !== '') {
            $feedback .= ' Aviso: ' . $warning;
            $feedbackClass = 'err';
        }
    }
}

$columns = ProtheusLerEdiService::tableColumns();

function ler_edi_query(array $overrides = []): string
{
    global $baseUrl, $filial, $job, $perPage;
    global $filterQ, $filterNota, $filterPedido, $filterPedMar, $filterMarketplace, $filterIdlexo;
    global $filterCod, $filterOcorrencia, $filterCte, $filterMatch, $filterTexto;

    $params = array_merge([
        'page' => 'protheus-ler-edi',
        'filial' => $filial,
        'job' => $job,
        'per_page' => (string) $perPage,
        'q' => $filterQ,
        'nota_fiscal' => $filterNota,
        'pedido' => $filterPedido,
        'ped_mar' => $filterPedMar,
        'marketplace' => $filterMarketplace,
        'idlexo' => $filterIdlexo,
        'cod_ocorrencia' => $filterCod,
        'ocorrencia' => $filterOcorrencia,
        'cte' => $filterCte,
        'match' => $filterMatch,
        'texto' => $filterTexto,
    ], $overrides);

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }

    return portal_wct_public_path($baseUrl, 'index.php?' . http_build_query($params));
}
?>
<section class="card protheus-monitor-card">
    <h1>Ler EDI (OCOREN)</h1>
    <p>
        Faz upload do arquivo <strong>OCOREN 5.0</strong> da transportadora, interpreta os registros
        (<code>000/540/541/542/543/544/549</code>) e cruza com o pedido do Protheus pela
        <strong>nota fiscal</strong> (<strong>SF2</strong> + <strong>SC5</strong> + <strong>ZA4</strong>).
    </p>
    <p style="font-size:.9rem;color:#64748b;">
        Layout TIVIT — registro de 250 caracteres. A tabela destaca linhas sem pedido no Protheus (vermelho)
        e ocorrencias diferentes de entrega normal <code>001</code> (laranja).
    </p>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="protheus-filters" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-ler-edi')) ?>">
        <input type="hidden" name="form_type" value="ler_edi_upload">
        <div class="filter-grid upload-grid">
            <label>Filial Protheus
                <input type="text" name="filial" value="<?= htmlspecialchars($filial) ?>" maxlength="4" required>
            </label>
            <label>Arquivo OCOREN
                <input type="file" name="ocoren" accept=".txt,.edi,.ocoren,text/plain" required>
            </label>
            <label class="chk-label">Cruzar com Protheus
                <span class="chk-wrap">
                    <input type="checkbox" name="cross_protheus" value="1"<?= $crossProtheus ? ' checked' : '' ?><?= $canCross ? '' : ' disabled' ?>>
                    <?= $canCross ? 'Buscar pedido pela NF' : 'Configure o Protheus antes' ?>
                </span>
            </label>
        </div>
        <button type="submit">Ler arquivo</button>
    </form>

    <?php if (is_array($result)): ?>
        <?php
        $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
        $sourceRows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $allRows = $lerEdiService->filterRows($sourceRows, [
            'q' => $filterQ,
            'nota_fiscal' => $filterNota,
            'pedido' => $filterPedido,
            'ped_mar' => $filterPedMar,
            'marketplace' => $filterMarketplace,
            'idlexo' => $filterIdlexo,
            'cod_ocorrencia' => $filterCod,
            'ocorrencia' => $filterOcorrencia,
            'cte' => $filterCte,
            'match' => $filterMatch,
            'texto' => $filterTexto,
        ]);
        $totalSource = count($sourceRows);
        $totalRows = count($allRows);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        if ($pageNum > $totalPages) {
            $pageNum = $totalPages;
        }
        $offset = ($pageNum - 1) * $perPage;
        $rows = array_slice($allRows, $offset, $perPage);
        $hasFilters = $filterQ !== '' || $filterNota !== '' || $filterPedido !== '' || $filterPedMar !== ''
            || $filterMarketplace !== '' || $filterIdlexo !== '' || $filterCod !== '' || $filterOcorrencia !== ''
            || $filterCte !== '' || $filterMatch !== '' || $filterTexto !== '';
        ?>
        <div class="protheus-summary-row">
            <p class="protheus-summary">
                Arquivo: <strong><?= htmlspecialchars((string) ($meta['arquivo'] ?? '')) ?></strong>
                | Remetente: <strong><?= htmlspecialchars((string) ($meta['remetente'] ?? '')) ?></strong>
                | Destinatario: <strong><?= htmlspecialchars((string) ($meta['destinatario'] ?? '')) ?></strong>
                | Transportadora: <strong><?= htmlspecialchars((string) ($meta['razao_transportadora'] ?? '')) ?></strong>
                (<?= htmlspecialchars((string) ($meta['cnpj_transportadora'] ?? '')) ?>)
                | Intercambio: <strong><?= htmlspecialchars((string) ($meta['id_intercambio'] ?? '')) ?></strong>
                | Ocorrencias: <strong><?= (int) ($summary['ocorrencias'] ?? $totalSource) ?></strong>
                | Com pedido: <strong><?= (int) ($summary['matched'] ?? 0) ?></strong>
                | Sem pedido: <strong><?= (int) ($summary['unmatched'] ?? 0) ?></strong>
                <?php if ($hasFilters): ?>
                    | Filtrado: <strong><?= (int) $totalRows ?></strong> de <?= (int) $totalSource ?>
                <?php endif; ?>
            </p>
            <a class="btn-export-xlsx" href="<?= htmlspecialchars(ler_edi_query(['export' => 'xlsx', 'p' => null])) ?>">Exportar Excel</a>
        </div>

        <form method="get" class="protheus-filters result-filters">
            <input type="hidden" name="page" value="protheus-ler-edi">
            <input type="hidden" name="job" value="<?= htmlspecialchars($job) ?>">
            <input type="hidden" name="filial" value="<?= htmlspecialchars($filial) ?>">
            <div class="filter-grid result-grid">
                <label>Busca geral
                    <input type="text" name="q" value="<?= htmlspecialchars($filterQ) ?>" placeholder="NF, pedido, texto...">
                </label>
                <label>Nota fiscal
                    <input type="text" name="nota_fiscal" value="<?= htmlspecialchars($filterNota) ?>" placeholder="Ex.: 000557110">
                </label>
                <label>Pedido Protheus
                    <input type="text" name="pedido" value="<?= htmlspecialchars($filterPedido) ?>" placeholder="C5_NUM">
                </label>
                <label>Ped. marketplace
                    <input type="text" name="ped_mar" value="<?= htmlspecialchars($filterPedMar) ?>" placeholder="ZA4/SC5">
                </label>
                <label>Marketplace
                    <input type="text" name="marketplace" value="<?= htmlspecialchars($filterMarketplace) ?>" placeholder="Ex.: MERCADO LIVRE">
                </label>
                <label>ID Lexos
                    <input type="text" name="idlexo" value="<?= htmlspecialchars($filterIdlexo) ?>" placeholder="C5_ZIDLEX">
                </label>
                <label>Cod. ocorrencia
                    <input type="text" name="cod_ocorrencia" value="<?= htmlspecialchars($filterCod) ?>" placeholder="Ex.: 001" maxlength="10">
                </label>
                <label>Ocorrencia
                    <input type="text" name="ocorrencia" value="<?= htmlspecialchars($filterOcorrencia) ?>" placeholder="Ex.: Entrega Realizada">
                </label>
                <label>CTE
                    <input type="text" name="cte" value="<?= htmlspecialchars($filterCte) ?>" placeholder="Nº conhecimento">
                </label>
                <label>Texto / descricao
                    <input type="text" name="texto" value="<?= htmlspecialchars($filterTexto) ?>" placeholder="Ex.: ENTREGA REALIZADA">
                </label>
                <label>Match Protheus
                    <select name="match">
                        <option value="">Todos</option>
                        <option value="sim"<?= $filterMatch === 'sim' ? ' selected' : '' ?>>Com pedido</option>
                        <option value="nao"<?= $filterMatch === 'nao' ? ' selected' : '' ?>>Sem pedido</option>
                    </select>
                </label>
                <label>Por pagina
                    <select name="per_page">
                        <?php foreach ([50, 100, 150, 200] as $opt): ?>
                            <option value="<?= $opt ?>"<?= $perPage === $opt ? ' selected' : '' ?>><?= $opt ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="filter-actions">
                <button type="submit">Filtrar</button>
                <?php if ($hasFilters): ?>
                    <a class="btn-clear" href="<?= htmlspecialchars(ler_edi_query([
                        'q' => null,
                        'nota_fiscal' => null,
                        'pedido' => null,
                        'ped_mar' => null,
                        'marketplace' => null,
                        'idlexo' => null,
                        'cod_ocorrencia' => null,
                        'ocorrencia' => null,
                        'cte' => null,
                        'match' => null,
                        'texto' => null,
                        'p' => null,
                    ])) ?>">Limpar filtros</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="table-wrap">
            <table class="protheus-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $label): ?>
                            <th><?= htmlspecialchars($label) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="<?= count($columns) ?>"><?= $hasFilters ? 'Nenhuma ocorrencia com esses filtros.' : 'Nenhuma ocorrencia no arquivo.' ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $row = is_array($row) ? $row : [];
                            $alertClass = $lerEdiService->rowAlertClass($row);
                            ?>
                            <tr class="<?= htmlspecialchars($alertClass) ?>">
                                <?php foreach (array_keys($columns) as $key): ?>
                                    <td><?= $lerEdiService->displayCellHtml((string) $key, $row[$key] ?? null) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1 || $totalRows > 0): ?>
            <nav class="pagination">
                <?php if ($pageNum > 1): ?>
                    <a href="<?= htmlspecialchars(ler_edi_query(['p' => (string) ($pageNum - 1)])) ?>">&laquo; Anterior</a>
                <?php endif; ?>
                <span>Pagina <?= (int) $pageNum ?> / <?= (int) $totalPages ?> (<?= (int) $totalRows ?> linha<?= $totalRows === 1 ? '' : 's' ?>)</span>
                <?php if ($pageNum < $totalPages): ?>
                    <a href="<?= htmlspecialchars(ler_edi_query(['p' => (string) ($pageNum + 1)])) ?>">Proxima &raquo;</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<style>
    .protheus-monitor-card { width: 100%; max-width: 100%; }
    .protheus-monitor-card h1 { font-size: 1.25rem; margin-bottom: 8px; }
    .protheus-filters .filter-grid {
        display: grid;
        gap: 10px 14px;
        margin-top: 6px;
        align-items: end;
    }
    .protheus-filters .upload-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .protheus-filters .result-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .protheus-filters label { margin-top: 0; font-weight: bold; font-size: .85rem; }
    .protheus-filters input[type="text"],
    .protheus-filters input[type="file"],
    .protheus-filters select {
        margin-top: 4px;
        width: 100%;
        box-sizing: border-box;
    }
    .result-filters {
        margin: 12px 0 8px;
        padding: 12px;
        border: 1px solid var(--wct-border);
        border-radius: 8px;
        background: #f8fafc;
    }
    .filter-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 10px;
    }
    a.btn-clear {
        font-size: .85rem;
        color: #334155;
        text-decoration: underline;
    }
    .chk-label .chk-wrap {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 8px;
        font-weight: normal;
        font-size: .85rem;
    }
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
        max-height: 70vh;
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
        vertical-align: top;
        text-align: left;
        white-space: nowrap;
    }
    .protheus-table thead th {
        position: sticky;
        top: 0;
        background: #f1f5f9;
        font-size: .75rem;
        text-transform: uppercase;
        z-index: 1;
    }
    tr.row-edi-alerta { background: #ffedd5 !important; }
    tr.row-edi-erro { background: #fee2e2 !important; }
    .cell-desc {
        max-width: 320px;
        display: inline-block;
        white-space: normal;
    }
    .badge-ok {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        background: #dcfce7;
        color: #166534;
        font-weight: 700;
        font-size: .72rem;
    }
    .badge-err {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        background: #fee2e2;
        color: #991b1b;
        font-weight: 700;
        font-size: .72rem;
    }
    .pagination { display: flex; gap: 14px; margin-top: 12px; font-size: .88rem; }
    @media (max-width: 1100px) {
        .protheus-filters .result-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .protheus-filters .upload-grid { grid-template-columns: 1fr; }
    }
</style>
