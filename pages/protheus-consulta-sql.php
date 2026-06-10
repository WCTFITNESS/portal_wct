<?php

declare(strict_types=1);

$settings = $app['protheusSettingsRepository']->getSettings();
$driverAvailable = $app['protheusConnectionService']->isDriverAvailable();
$configured = $settings !== null && $driverAvailable;

$defaultTable = 'ZA4010';
$defaultWhere = "ZA4_STATUS = '3'";
$apiBase = portal_wct_public_path($baseUrl, 'index.php?page=protheus-consulta-sql');
?>
<section class="card protheus-sql-card">
    <div class="sql-page-toolbar">
        <button type="button" id="sql-saved-open"<?= $configured ? '' : ' disabled' ?>>
            Queries salvas
        </button>
    </div>

    <h1>Consulta SQL Protheus</h1>
    <p>
        <strong>Query pronta:</strong> cole o SQL completo (incluindo <code>SELECT COUNT(1)</code>) e execute.
        Ou use o montador com tabela, WHERE e ORDER BY — marque <strong>Apenas contagem</strong> para um COUNT sem escolher colunas.
        Somente leitura; tabelas do montador usam <code>WITH (NOLOCK)</code>.
    </p>

    <?php if (!$configured): ?>
        <p class="msg err">
            <?php if ($settings === null): ?>
                Configure o Protheus em Config Protheus antes de consultar.
            <?php else: ?>
                Driver SQL Server (pdo_sqlsrv ou pdo_dblib) nao disponivel neste PHP.
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <div class="sql-layout">
    <div class="sql-main">
    <section class="sql-ready-panel" id="sql-ready-panel"<?= $configured ? '' : ' style="opacity:.6;pointer-events:none"' ?>>
        <h2>Query pronta (SQL completo)</h2>
        <p class="picker-hint" style="margin:0 0 8px;">
            Cole o SELECT enviado pelo consultor (ex.: <code>SELECT COUNT(1) FROM GXL010 WHERE ...</code>).
            Agregados COUNT/SUM/AVG nao recebem TOP automatico; demais SELECTs sem TOP sao limitados a 2000 linhas.
        </p>
        <label class="sql-ready-title-label">Titulo (ao salvar na biblioteca)
            <input type="text" id="sql-ready-title" placeholder="Ex.: EDI ocorrencias GWD — consultor Joao" maxlength="120">
        </label>
        <label>SQL
            <textarea id="sql-ready-text" rows="12" spellcheck="false" placeholder="SELECT COUNT(1) FROM GXL010&#10;WHERE GXL_DTIMP &lt; '20260101'&#10;  AND D_E_L_E_T_ = ' '&#10;ORDER BY R_E_C_N_O_ DESC"></textarea>
        </label>
        <div class="sql-actions">
            <button type="button" id="sql-ready-run">Executar query pronta</button>
            <button type="button" id="sql-ready-save">Salvar na biblioteca</button>
            <button type="button" id="sql-ready-clear" class="btn-mini">Limpar</button>
        </div>
    </section>

    <div class="sql-builder-head">
        <h2 class="sql-builder-title">Montador de consulta</h2>
        <button type="button" id="sql-history-open"<?= $configured ? '' : ' disabled' ?>>
            Historico de consultas
        </button>
    </div>
    <form id="protheus-sql-form" class="protheus-sql-form"<?= $configured ? '' : ' style="opacity:.6;pointer-events:none"' ?>>
        <div class="filter-grid">
            <div class="sql-table-column">
                <div class="sql-query-options">
                    <label id="sql-top-wrap">Limite (TOP)
                        <select id="sql-top" name="top">
                            <?php foreach ([50, 100, 200, 500, 1000, 2000] as $opt): ?>
                                <option value="<?= $opt ?>"<?= $opt === 200 ? ' selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="sql-count-only-label" id="sql-count-only-wrap">
                        <input type="checkbox" id="sql-count-only" name="count_only" value="1">
                        Apenas contagem (COUNT)
                    </label>
                </div>
                <div class="picker-block">
                    <label for="sql-table-filter">Tabela</label>
                    <input
                        type="search"
                        id="sql-table-filter"
                        placeholder="Filtrar tabelas (ex.: ZA4, SC5)…"
                        autocomplete="off"
                    >
                    <select id="sql-table" name="table" size="8" required aria-label="Lista de tabelas">
                        <option value="">Carregando tabelas…</option>
                    </select>
                    <span class="picker-hint" id="sql-table-hint"></span>
                </div>
            </div>

            <div class="picker-block sql-columns-block">
                <label for="sql-columns-filter">Colunas</label>
                <div class="columns-toolbar">
                    <input
                        type="search"
                        id="sql-columns-filter"
                        placeholder="Filtrar colunas…"
                        autocomplete="off"
                        disabled
                    >
                    <button type="button" id="sql-cols-all" class="btn-mini" disabled>Todas (*)</button>
                    <button type="button" id="sql-cols-none" class="btn-mini" disabled>Limpar</button>
                </div>
                <select id="sql-columns" name="columns" size="10" multiple disabled aria-label="Lista de colunas">
                    <option value="">Selecione uma tabela</option>
                </select>
                <span class="picker-hint" id="sql-columns-hint">Ctrl+clique para varias colunas; duplo-clique na coluna insere no WHERE. Para COUNT, marque &quot;Apenas contagem&quot; acima.</span>
            </div>
        </div>

        <div class="sql-clauses-grid">
            <label class="sql-clause-field">WHERE
                <div class="sql-input-wrap">
                    <textarea id="sql-where" name="where" rows="4" placeholder="ZA4_STATUS = '3' AND ZA4_FILIAL = '0101'" required autocomplete="off" spellcheck="false"><?= htmlspecialchars($defaultWhere) ?></textarea>
                </div>
                <span class="picker-hint">Autocomplete com setas + Enter. No Protheus, STATUS/filial costumam ser texto: use aspas (ex.: ZA4_STATUS = '3'). So campos realmente int/float no banco vao sem aspas.</span>
            </label>
            <label class="sql-clause-field">ORDER BY <span class="label-hint">(opcional)</span>
                <div class="sql-input-wrap">
                    <input
                        type="text"
                        id="sql-order-by"
                        name="order_by"
                        placeholder="B1_COD DESC, B1_DESC ASC"
                        autocomplete="off"
                        spellcheck="false"
                    >
                </div>
                <span class="picker-hint">Somente coluna + ASC/DESC (nao digite ORDER BY). Ex.: B1_DESC DESC</span>
            </label>
        </div>

        <div id="sql-autocomplete" class="sql-autocomplete" hidden role="listbox"></div>

        <div class="sql-actions">
            <button type="submit" id="sql-btn-run">Executar</button>
            <button type="button" id="sql-btn-stop" class="btn-stop" disabled>Parar consulta</button>
        </div>
    </form>

    <div id="sql-status" class="sql-status" hidden></div>

    <div id="sql-results-toolbar" class="sql-results-toolbar" hidden>
        <span id="sql-results-count" class="sql-results-count"></span>
        <a id="sql-export-btn" class="btn-export-xlsx" href="#" hidden>Exportar Excel</a>
    </div>

    <pre id="sql-preview" class="sql-preview" hidden></pre>

    <div id="sql-results-wrap" class="table-wrap sql-results-wrap" hidden>
        <table class="protheus-table sql-results-table" id="sql-results-table">
            <thead id="sql-results-head"></thead>
            <tbody id="sql-results-body"></tbody>
        </table>
    </div>

    <div id="sql-cell-modal" class="sql-cell-modal" hidden aria-hidden="true">
        <div class="sql-cell-modal-backdrop" data-close-cell-modal></div>
        <div class="sql-cell-modal-box" role="dialog" aria-labelledby="sql-cell-modal-title">
            <h3 id="sql-cell-modal-title">Valor completo</h3>
            <pre id="sql-cell-modal-body"></pre>
            <button type="button" id="sql-cell-modal-close" class="btn-mini">Fechar</button>
        </div>
    </div>
    </div><!-- .sql-main -->
    </div><!-- .sql-layout -->

    <div id="sql-history-modal" class="sql-panel-modal" hidden aria-hidden="true">
        <div class="sql-panel-modal-backdrop" data-close-history-modal></div>
        <div class="sql-panel-modal-box" role="dialog" aria-labelledby="sql-history-modal-title">
            <div class="sql-panel-modal-head">
                <h2 id="sql-history-modal-title">Historico de consultas</h2>
                <div class="sql-panel-modal-actions">
                    <button type="button" id="sql-history-refresh" class="btn-mini" title="Atualizar lista">↻</button>
                    <button type="button" id="sql-history-close" class="btn-mini" aria-label="Fechar">×</button>
                </div>
            </div>
            <div class="sql-panel-modal-body sql-panel-modal-body-single">
                <p class="sql-panel-modal-hint">Consultas executadas no montador ou em query pronta nesta sessao e anteriores.</p>
                <ul id="sql-history-list" class="sql-history-list sql-history-list-modal">
                    <li class="sql-history-empty">Carregando…</li>
                </ul>
            </div>
        </div>
    </div>

    <div id="sql-saved-modal" class="sql-panel-modal" hidden aria-hidden="true">
        <div class="sql-panel-modal-backdrop" data-close-saved-modal></div>
        <div class="sql-panel-modal-box" role="dialog" aria-labelledby="sql-saved-modal-title">
            <div class="sql-panel-modal-head">
                <h2 id="sql-saved-modal-title">Queries salvas</h2>
                <div class="sql-panel-modal-actions">
                    <button type="button" id="sql-saved-refresh" class="btn-mini" title="Atualizar">↻</button>
                    <button type="button" id="sql-saved-close" class="btn-mini" aria-label="Fechar">×</button>
                </div>
            </div>
            <div class="sql-panel-modal-body sql-panel-modal-body-single">
                <p class="sql-panel-modal-hint">SQL completo salvo na biblioteca (query pronta).</p>
                <ul id="sql-saved-list" class="sql-history-list sql-saved-list sql-saved-list-modal">
                    <li class="sql-history-empty">Carregando…</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<style>
    /* Botões WCT (preto + dourado) — toda a tela de consulta SQL */
    .protheus-sql-card button,
    .protheus-sql-card a.btn-export-xlsx {
        box-sizing: border-box;
        margin-top: 0;
        padding: 10px 16px;
        border: 1px solid #f5b700;
        border-radius: 6px;
        background: #111111;
        color: #f5b700;
        font-weight: bold;
        font-size: .78rem;
        letter-spacing: .04em;
        text-transform: uppercase;
        cursor: pointer;
        text-decoration: none;
        line-height: 1.2;
        font-family: inherit;
    }
    .protheus-sql-card button:hover:not(:disabled),
    .protheus-sql-card a.btn-export-xlsx:hover {
        background: #f5b700;
        color: #111111;
        border-color: #f5b700;
    }
    .protheus-sql-card button:disabled {
        opacity: .55;
        cursor: not-allowed;
        background: #202020;
        color: #d1a93a;
        border-color: #d1a93a;
    }
    .protheus-sql-card .btn-mini {
        padding: 6px 12px;
        font-size: .72rem;
    }
    .protheus-sql-card .sql-panel-modal-actions .btn-mini {
        min-width: 32px;
        height: 32px;
        padding: 0;
        font-size: 1.1rem;
    }
    .protheus-sql-card a.btn-export-xlsx {
        display: inline-block;
        white-space: nowrap;
    }

    .protheus-sql-card > .sql-page-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 0 0 10px;
        padding-bottom: 2px;
    }
    .sql-builder-head {
        position: sticky;
        top: 0;
        z-index: 120;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px 16px;
        margin: 4px 0 12px;
        padding: 8px 0 10px;
        background: #fff;
        border-bottom: 1px solid #e8edf5;
    }
    .sql-builder-head .sql-builder-title {
        margin: 0;
    }
    .sql-layout {
        margin-top: 0;
    }
    .sql-ready-panel {
        margin-bottom: 20px;
        padding: 14px 16px;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 8px;
    }
    .sql-ready-panel h2,
    .sql-builder-title {
        margin: 0 0 10px;
        font-size: 1rem;
    }
    .sql-ready-panel label {
        display: block;
        font-weight: bold;
        font-size: .85rem;
        margin-top: 10px;
    }
    .sql-ready-title-label input,
    #sql-ready-text {
        width: 100%;
        margin-top: 4px;
        box-sizing: border-box;
        font-family: ui-monospace, Consolas, monospace;
        font-size: .82rem;
    }
    #sql-ready-text {
        min-height: 180px;
        resize: vertical;
    }
    .sql-history-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
    }
    .sql-history-head h3 {
        margin: 0;
        font-size: .92rem;
    }
    .sql-history-list {
        list-style: none;
        margin: 0;
        padding: 0;
        overflow-y: auto;
        max-height: min(38vh, 320px);
        min-height: 80px;
    }
    .sql-saved-list {
        max-height: min(32vh, 260px);
    }
    .sql-history-list-modal,
    .sql-saved-list-modal {
        max-height: min(62vh, 520px);
    }
    .sql-panel-modal {
        position: fixed;
        inset: 0;
        z-index: 2100;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .sql-panel-modal[hidden] {
        display: none !important;
    }
    .sql-panel-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, .55);
    }
    .sql-panel-modal-box {
        position: relative;
        z-index: 1;
        width: min(640px, 100%);
        max-height: 88vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        background: #fff;
        border-radius: 8px;
        padding: 0;
        box-shadow: 0 12px 40px rgba(0, 0, 0, .25);
        border: 1px solid var(--wct-border);
    }
    .sql-panel-modal-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .sql-panel-modal-head h2 {
        margin: 0;
        font-size: 1.05rem;
    }
    .sql-panel-modal-actions {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .sql-panel-modal-body {
        overflow-y: auto;
        padding: 14px 16px 18px;
    }
    .sql-panel-modal-body-single {
        display: block;
    }
    .sql-panel-modal-hint {
        margin: 0 0 10px;
        font-size: .8rem;
        color: #64748b;
    }
    .sql-history-list li {
        margin-bottom: 6px;
    }
    .sql-history-row {
        display: flex;
        align-items: stretch;
        gap: 4px;
    }
    .sql-history-row .sql-history-item {
        flex: 1;
        min-width: 0;
    }
    .sql-history-delete,
    .sql-saved-delete {
        flex-shrink: 0;
        width: 32px;
        min-width: 32px;
        padding: 0;
        font-size: 1.1rem;
        line-height: 1;
        align-self: stretch;
        text-transform: none;
    }
    .sql-history-item {
        display: block;
        width: 100%;
        text-align: left;
        padding: 8px 12px;
        text-transform: none;
        letter-spacing: 0;
        font-size: .75rem;
        line-height: 1.35;
    }
    .sql-history-item:hover:not(:disabled) {
        background: #f5b700;
        color: #111111;
        border-color: #f5b700;
    }
    .sql-history-item.active {
        background: #f5b700;
        color: #111111;
        border-color: #f5b700;
    }
    .sql-history-item strong {
        display: block;
        font-size: .78rem;
        color: inherit;
    }
    .sql-history-item .hist-meta {
        opacity: .85;
        margin-top: 3px;
    }
    .sql-history-item .hist-where {
        opacity: .9;
        margin-top: 2px;
        font-family: ui-monospace, Consolas, monospace;
        font-size: .7rem;
    }
    .sql-history-item:hover .hist-meta,
    .sql-history-item:hover .hist-where,
    .sql-history-item.active .hist-meta,
    .sql-history-item.active .hist-where {
        color: inherit;
    }
    .sql-history-empty {
        color: #94a3b8;
        font-size: .8rem;
        padding: 8px 4px;
    }
    .sql-results-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin: 10px 0 6px;
    }
    .sql-results-count {
        font-size: .88rem;
        color: #334155;
        font-weight: bold;
    }
    .sql-results-table td.sql-col-num,
    .sql-results-table th.sql-col-num {
        width: 3rem;
        min-width: 3rem;
        max-width: 3rem;
        text-align: right;
        color: #64748b;
        font-weight: bold;
        background: #f1f5f9;
        position: sticky;
        left: 0;
        z-index: 1;
    }
    .sql-results-table thead th.sql-col-num {
        z-index: 3;
    }
    .protheus-sql-form .filter-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px 16px;
        margin-top: 6px;
        align-items: start;
    }
    .sql-table-column {
        display: flex;
        flex-direction: column;
        gap: 10px;
        min-width: 0;
    }
    .sql-query-options {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 10px 18px;
        min-height: 52px;
    }
    #sql-top-wrap {
        flex: 0 0 100px;
        margin: 0;
    }
    #sql-top-wrap select {
        display: block;
        width: 100%;
        margin-top: 4px;
    }
    .sql-count-only-label {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: bold;
        font-size: .85rem;
        margin: 0;
        padding-bottom: 6px;
        flex: 1 1 auto;
        min-width: 160px;
    }
    .sql-count-only-label input {
        width: auto;
        margin: 0;
        flex-shrink: 0;
    }
    .sql-columns-block {
        min-height: 0;
    }
    .protheus-sql-form label { margin-top: 0; font-weight: bold; font-size: .85rem; }
    .picker-block label { display: block; margin-bottom: 4px; }
    .picker-block input[type="search"],
    .picker-block select,
    .protheus-sql-form textarea,
    .protheus-sql-form #sql-top {
        width: 100%;
        margin-top: 0;
        box-sizing: border-box;
    }
    .picker-block select {
        margin-top: 6px;
        font-family: ui-monospace, Consolas, monospace;
        font-size: .8rem;
        min-height: 160px;
    }
    #sql-columns { min-height: 200px; }
    .picker-hint {
        display: block;
        margin-top: 4px;
        font-size: .75rem;
        color: #64748b;
        font-weight: normal;
    }
    .columns-toolbar {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        margin-top: 0;
    }
    .columns-toolbar input[type="search"] {
        flex: 1 1 140px;
        min-width: 120px;
        margin-top: 0 !important;
    }
    .protheus-sql-form textarea {
        font-family: ui-monospace, Consolas, monospace;
        font-size: .88rem;
        margin-top: 4px;
    }
    .protheus-sql-form #sql-top { margin-top: 4px; }
    .sql-clauses-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px 16px;
        margin-top: 4px;
    }
    .sql-clauses-grid label { margin-top: 0; }
    .sql-clauses-grid textarea,
    .sql-clauses-grid input { margin-top: 4px; }
    .sql-input-wrap { position: relative; margin-top: 4px; }
    .sql-autocomplete {
        position: fixed;
        z-index: 1500;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .15);
        max-height: 220px;
        overflow-y: auto;
        min-width: 160px;
    }
    .sql-autocomplete-item {
        display: block;
        width: 100%;
        text-align: left;
        padding: 7px 12px;
        border: none;
        border-radius: 0;
        background: #111111;
        color: #f5b700;
        font-family: ui-monospace, Consolas, monospace;
        font-size: .8rem;
        font-weight: bold;
        text-transform: none;
        letter-spacing: 0;
        cursor: pointer;
    }
    .sql-autocomplete-item:hover,
    .sql-autocomplete-item.active {
        background: #f5b700;
        color: #111111;
    }
    .label-hint {
        font-weight: normal;
        color: #64748b;
        font-size: .75rem;
    }
    .sql-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin-top: 4px;
    }
    .sql-status {
        margin: 14px 0 8px;
        padding: 10px 12px;
        border-radius: 6px;
        font-size: .88rem;
    }
    .sql-status.ok { background: #e7f8ec; color: #0f6d2e; }
    .sql-status.err { background: #ffe9e9; color: #a12323; }
    .sql-status.run { background: #eff6ff; color: #1e40af; }
    .sql-preview {
        margin: 0 0 12px;
        padding: 10px 12px;
        background: #0f172a;
        color: #e2e8f0;
        border-radius: 6px;
        font-size: .78rem;
        overflow-x: auto;
        white-space: pre-wrap;
        word-break: break-all;
    }
    .sql-results-wrap {
        width: 100%;
        max-width: 100%;
        max-height: min(70vh, 720px);
        overflow: auto;
        border: 1px solid var(--wct-border);
        border-radius: 6px;
    }
    .sql-results-table {
        width: max-content;
        min-width: 100%;
        border-collapse: collapse;
        font-size: .75rem;
        table-layout: fixed;
    }
    .sql-results-table thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #e2e8f0;
        white-space: nowrap;
        font-size: .68rem;
        text-transform: uppercase;
        padding: 6px 8px;
        border-bottom: 2px solid #cbd5e1;
        max-width: 11rem;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .sql-results-table tbody tr {
        height: 1.75rem;
    }
    .sql-results-table tbody tr:nth-child(even) {
        background: #f8fafc;
    }
    .sql-results-table tbody tr:hover {
        background: #fffbeb;
    }
    .sql-results-table td.sql-cell {
        padding: 2px 8px;
        border-bottom: 1px solid #e8edf5;
        text-align: left;
        vertical-align: middle;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 11rem;
        line-height: 1.35;
        height: 1.75rem;
    }
    .sql-results-table td.sql-cell-truncated {
        cursor: pointer;
        color: #1d4ed8;
        text-decoration: underline dotted;
    }
    .sql-results-table td.sql-cell-truncated:hover {
        background: #dbeafe;
    }
    .sql-results-table td.sql-cell-empty {
        color: #94a3b8;
    }
    .sql-cell-modal {
        position: fixed;
        inset: 0;
        z-index: 2000;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .sql-cell-modal[hidden] {
        display: none !important;
    }
    .sql-cell-modal-backdrop {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, .55);
    }
    .sql-cell-modal-box {
        position: relative;
        z-index: 1;
        width: min(640px, 100%);
        max-height: 80vh;
        overflow: auto;
        background: #fff;
        border-radius: 8px;
        padding: 16px 18px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, .25);
        border: 1px solid var(--wct-border);
    }
    .sql-cell-modal-box h3 {
        margin: 0 0 10px;
        font-size: 1rem;
    }
    .sql-cell-modal-box pre {
        margin: 0 0 14px;
        padding: 12px;
        background: #f1f5f9;
        border-radius: 6px;
        white-space: pre-wrap;
        word-break: break-word;
        font-size: .85rem;
        font-family: ui-monospace, Consolas, monospace;
        max-height: 50vh;
        overflow: auto;
    }
    @media (max-width: 900px) {
        .protheus-sql-form .filter-grid { grid-template-columns: 1fr; }
        .sql-clauses-grid { grid-template-columns: 1fr; }
    }
</style>

<script>
(function () {
    const apiBase = <?= json_encode($apiBase, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
    const exportBase = apiBase;
    const defaultTable = <?= json_encode($defaultTable, JSON_UNESCAPED_UNICODE) ?>;
    const configured = <?= $configured ? 'true' : 'false' ?>;

    const form = document.getElementById('protheus-sql-form');
    const btnRun = document.getElementById('sql-btn-run');
    const btnStop = document.getElementById('sql-btn-stop');
    const statusEl = document.getElementById('sql-status');
    const previewEl = document.getElementById('sql-preview');
    const wrapEl = document.getElementById('sql-results-wrap');
    const headEl = document.getElementById('sql-results-head');
    const bodyEl = document.getElementById('sql-results-body');

    const tableFilter = document.getElementById('sql-table-filter');
    const tableSelect = document.getElementById('sql-table');
    const tableHint = document.getElementById('sql-table-hint');
    const columnsFilter = document.getElementById('sql-columns-filter');
    const columnsSelect = document.getElementById('sql-columns');
    const columnsHint = document.getElementById('sql-columns-hint');
    const btnColsAll = document.getElementById('sql-cols-all');
    const btnColsNone = document.getElementById('sql-cols-none');
    const countOnlyInput = document.getElementById('sql-count-only');
    const topWrap = document.getElementById('sql-top-wrap');
    const whereInput = document.getElementById('sql-where');
    const orderByInput = document.getElementById('sql-order-by');
    const autocompleteEl = document.getElementById('sql-autocomplete');
    const historyList = document.getElementById('sql-history-list');
    const historyRefresh = document.getElementById('sql-history-refresh');
    const savedList = document.getElementById('sql-saved-list');
    const savedRefresh = document.getElementById('sql-saved-refresh');
    const rawText = document.getElementById('sql-ready-text');
    const rawTitle = document.getElementById('sql-ready-title');
    const btnRawRun = document.getElementById('sql-ready-run');
    const btnRawSave = document.getElementById('sql-ready-save');
    const btnRawClear = document.getElementById('sql-ready-clear');
    const resultsToolbar = document.getElementById('sql-results-toolbar');
    const resultsCount = document.getElementById('sql-results-count');
    const exportBtn = document.getElementById('sql-export-btn');

    let abortController = null;
    let lastHistoryId = null;
    let activeQueryId = null;
    let tableDebounce = null;
    let allColumnOptions = [];
    let columnsLoading = false;
    let acItems = [];
    let acIndex = -1;
    let acTargetEl = null;
    const CELL_CHAR_LIMIT = 20;
    const cellFullValues = new Map();

    const cellModal = document.getElementById('sql-cell-modal');
    const cellModalTitle = document.getElementById('sql-cell-modal-title');
    const cellModalBody = document.getElementById('sql-cell-modal-body');
    const cellModalClose = document.getElementById('sql-cell-modal-close');
    const historyModal = document.getElementById('sql-history-modal');
    const historyOpen = document.getElementById('sql-history-open');
    const historyClose = document.getElementById('sql-history-close');
    const savedModal = document.getElementById('sql-saved-modal');
    const savedOpen = document.getElementById('sql-saved-open');
    const savedClose = document.getElementById('sql-saved-close');

    function openHistoryModal() {
        if (!configured) return;
        historyModal.hidden = false;
        historyModal.setAttribute('aria-hidden', 'false');
        loadHistory();
    }

    function closeHistoryModal() {
        historyModal.hidden = true;
        historyModal.setAttribute('aria-hidden', 'true');
    }

    function openSavedModal() {
        if (!configured) return;
        savedModal.hidden = false;
        savedModal.setAttribute('aria-hidden', 'false');
        loadSaved();
    }

    function closeSavedModal() {
        savedModal.hidden = true;
        savedModal.setAttribute('aria-hidden', 'true');
    }

    function setStatus(text, kind) {
        statusEl.hidden = false;
        statusEl.textContent = text;
        statusEl.className = 'sql-status ' + (kind || 'run');
    }

    function setColumnPickersEnabled(enabled) {
        columnsFilter.disabled = !enabled;
        columnsSelect.disabled = !enabled;
        btnColsAll.disabled = !enabled;
        btnColsNone.disabled = !enabled;
    }

    function setRunning(running) {
        btnRun.disabled = running;
        btnStop.disabled = !running;
        tableFilter.disabled = running;
        tableSelect.disabled = running;
        whereInput.disabled = running;
        orderByInput.disabled = running;
        document.getElementById('sql-top').disabled = running;
        btnColsAll.disabled = running;
        btnColsNone.disabled = running;
        columnsFilter.disabled = running;
        columnsSelect.disabled = running;
        if (btnRawRun) btnRawRun.disabled = running;
        if (btnRawSave) btnRawSave.disabled = running;
        if (rawText) rawText.disabled = running;
        if (rawTitle) rawTitle.disabled = running;
        if (!running) {
            setColumnPickersEnabled(!!tableSelect.value && allColumnOptions.length > 0);
        }
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(s) {
        return escapeHtml(s).replace(/'/g, '&#39;');
    }

    function debounce(fn, ms) {
        return function () {
            const args = arguments;
            clearTimeout(tableDebounce);
            tableDebounce = setTimeout(function () { fn.apply(null, args); }, ms);
        };
    }

    async function fetchJson(url) {
        const res = await fetch(url);
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || ('HTTP ' + res.status));
        }
        return data;
    }

    function fillTableSelect(tables, selected) {
        tableSelect.innerHTML = '';
        if (!tables.length) {
            tableSelect.innerHTML = '<option value="">Nenhuma tabela encontrada</option>';
            tableHint.textContent = 'Ajuste o filtro e tente de novo.';
            return;
        }
        tables.forEach(function (name) {
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            if (selected && name.toUpperCase() === selected.toUpperCase()) {
                opt.selected = true;
            }
            tableSelect.appendChild(opt);
        });
        if (selected && !tableSelect.value) {
            const opt = document.createElement('option');
            opt.value = selected;
            opt.textContent = selected;
            opt.selected = true;
            tableSelect.insertBefore(opt, tableSelect.firstChild);
        }
        tableHint.textContent = tables.length + ' tabela(s) listada(s)';
    }

    async function loadTables(search, selectAfter) {
        const q = (search || '').trim();
        const url = apiBase + '&protheus_sql_action=tables' + (q ? '&q=' + encodeURIComponent(q) : '');
        tableSelect.innerHTML = '<option value="">Carregando…</option>';
        tableHint.textContent = 'Buscando tabelas…';
        try {
            const data = await fetchJson(url);
            fillTableSelect(data.tables || [], selectAfter || tableSelect.value);
            if (selectAfter && tableSelect.value === selectAfter) {
                await loadColumns(selectAfter);
            }
        } catch (err) {
            tableSelect.innerHTML = '<option value="">Erro ao carregar</option>';
            tableHint.textContent = 'Erro: ' + err.message;
        }
    }

    function buildColumnOption(col) {
        const opt = document.createElement('option');
        opt.value = col.name;
        let label = col.name;
        if (col.data_type) {
            label += ' (' + col.data_type;
            if (col.max_length != null && col.max_length > 0) {
                label += '(' + col.max_length + ')';
            }
            label += ')';
        }
        opt.textContent = label;
        opt.dataset.label = col.name.toLowerCase();
        return opt;
    }

    function renderColumnOptions(columns, filterText) {
        const q = (filterText || '').trim().toLowerCase();
        columnsSelect.innerHTML = '';
        const star = document.createElement('option');
        star.value = '*';
        star.textContent = '* (todas as colunas)';
        star.dataset.label = '*';
        columnsSelect.appendChild(star);

        let shown = 0;
        columns.forEach(function (col) {
            const opt = buildColumnOption(col);
            if (q && !opt.dataset.label.includes(q)) {
                return;
            }
            columnsSelect.appendChild(opt);
            shown++;
        });
        columnsHint.textContent = shown + ' coluna(s) visiveis — Ctrl+clique para selecionar varias';
    }

    async function loadColumns(table) {
        if (!table) {
            columnsSelect.innerHTML = '<option value="">Selecione uma tabela</option>';
            columnsSelect.disabled = true;
            columnsFilter.disabled = true;
            btnColsAll.disabled = true;
            btnColsNone.disabled = true;
            allColumnOptions = [];
            return;
        }
        columnsLoading = true;
        columnsSelect.disabled = true;
        columnsFilter.disabled = true;
        btnColsAll.disabled = true;
        btnColsNone.disabled = true;
        columnsSelect.innerHTML = '<option value="">Carregando colunas…</option>';
        columnsHint.textContent = 'Carregando colunas de ' + table + '…';

        try {
            const data = await fetchJson(
                apiBase + '&protheus_sql_action=columns&table=' + encodeURIComponent(table)
            );
            allColumnOptions = data.columns || [];
            renderColumnOptions(allColumnOptions, columnsFilter.value);
            columnsSelect.disabled = false;
            columnsFilter.disabled = false;
            btnColsAll.disabled = false;
            btnColsNone.disabled = false;
            const star = columnsSelect.querySelector('option[value="*"]');
            if (star) star.selected = true;
        } catch (err) {
            columnsSelect.innerHTML = '<option value="">Erro ao carregar colunas</option>';
            columnsHint.textContent = 'Erro: ' + err.message;
            allColumnOptions = [];
        } finally {
            columnsLoading = false;
        }
    }

    function getSelectedColumnsValue() {
        const selected = Array.from(columnsSelect.selectedOptions).map(function (o) { return o.value; });
        if (!selected.length || selected.includes('*')) {
            return '*';
        }
        return selected.join(', ');
    }

    function formatCellDisplay(value) {
        const full = String(value ?? '').trim();
        if (full === '') {
            return { display: '—', full: '', truncated: false, empty: true };
        }
        if (full.length <= CELL_CHAR_LIMIT) {
            return { display: full, full: full, truncated: false, empty: false };
        }
        return {
            display: full.slice(0, CELL_CHAR_LIMIT) + '…',
            full: full,
            truncated: true,
            empty: false,
        };
    }

    function renderCellHtml(col, row, rowIndex) {
        const cell = formatCellDisplay(row[col]);
        const classes = ['sql-cell'];
        if (cell.empty) classes.push('sql-cell-empty');
        if (cell.truncated) classes.push('sql-cell-truncated');

        let attrs = ' class="' + classes.join(' ') + '"';
        if (cell.truncated) {
            const cellKey = rowIndex + ':' + col;
            cellFullValues.set(cellKey, cell.full);
            attrs += ' role="button" tabindex="0" title="Clique para ver o valor completo"';
            attrs += ' data-col="' + escapeAttr(col) + '"';
            attrs += ' data-cell-key="' + escapeAttr(cellKey) + '"';
        } else if (!cell.empty && cell.full.length <= 80) {
            attrs += ' title="' + escapeAttr(cell.full) + '"';
        }

        return '<td' + attrs + '>' + escapeHtml(cell.display) + '</td>';
    }

    function openCellModal(col, fullValue) {
        cellModalTitle.textContent = col;
        cellModalBody.textContent = fullValue;
        cellModal.hidden = false;
        cellModal.setAttribute('aria-hidden', 'false');
    }

    function closeCellModal() {
        cellModal.hidden = true;
        cellModal.setAttribute('aria-hidden', 'true');
    }

    function normalizeOrderByClient(value) {
        let v = (value || '').trim();
        if (/^ORDER\s+BY\s+/i.test(v)) {
            v = v.replace(/^ORDER\s+BY\s+/i, '').trim();
        }
        return v;
    }

    function getColumnNames() {
        return (allColumnOptions || [])
            .map(function (col) { return (col.name || col || '').trim(); })
            .filter(function (name) { return name !== '' && name !== '*'; });
    }

    function insertIntoClause(el, text) {
        if (!el || !text) return;
        const start = el.selectionStart ?? el.value.length;
        const end = el.selectionEnd ?? el.value.length;
        const needSpaceBefore = start > 0 && !/[\s,(=<>]/.test(el.value.charAt(start - 1));
        const insert = (needSpaceBefore ? ' ' : '') + text;
        el.value = el.value.substring(0, start) + insert + el.value.substring(end);
        const pos = start + insert.length;
        el.selectionStart = el.selectionEnd = pos;
        el.focus();
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function getTokenAtCursor(text, pos) {
        const before = text.slice(0, pos);
        const match = before.match(/[A-Za-z0-9_]+$/);
        if (!match) return null;
        return { token: match[0], start: pos - match[0].length, end: pos };
    }

    function hideAutocomplete() {
        autocompleteEl.hidden = true;
        acItems = [];
        acIndex = -1;
        acTargetEl = null;
    }

    function renderAutocompleteList() {
        autocompleteEl.innerHTML = acItems.map(function (name, i) {
            return '<button type="button" class="sql-autocomplete-item' + (i === acIndex ? ' active' : '') + '" data-name="' + escapeAttr(name) + '">' +
                escapeHtml(name) + '</button>';
        }).join('');
        autocompleteEl.hidden = acItems.length === 0;
    }

    function positionAutocomplete(el) {
        const rect = el.getBoundingClientRect();
        autocompleteEl.style.left = Math.max(8, rect.left) + 'px';
        autocompleteEl.style.top = (rect.bottom + 4) + 'px';
        autocompleteEl.style.width = Math.max(rect.width, 200) + 'px';
    }

    function updateAutocomplete(el) {
        const names = getColumnNames();
        acTargetEl = el;
        if (!names.length) {
            hideAutocomplete();
            return;
        }
        const pos = el.selectionStart ?? el.value.length;
        const token = getTokenAtCursor(el.value, pos);
        if (!token || token.token.length < 1) {
            hideAutocomplete();
            return;
        }
        const q = token.token.toLowerCase();
        acItems = names.filter(function (n) {
            const lower = n.toLowerCase();
            return lower.startsWith(q) || lower.includes(q);
        }).slice(0, 20);
        if (!acItems.length) {
            hideAutocomplete();
            return;
        }
        if (acIndex < 0 || acIndex >= acItems.length) acIndex = 0;
        renderAutocompleteList();
        positionAutocomplete(el);
    }

    function applyAutocomplete(name) {
        if (!acTargetEl || !name) return;
        const el = acTargetEl;
        const pos = el.selectionStart ?? el.value.length;
        const token = getTokenAtCursor(el.value, pos);
        if (!token) return;
        el.value = el.value.slice(0, token.start) + name + el.value.slice(token.end);
        const newPos = token.start + name.length;
        el.selectionStart = el.selectionEnd = newPos;
        hideAutocomplete();
        el.focus();
    }

    function handleAutocompleteKey(ev, el) {
        if (autocompleteEl.hidden || !acItems.length || acTargetEl !== el) return;
        if (ev.key === 'ArrowDown') {
            ev.preventDefault();
            acIndex = (acIndex + 1) % acItems.length;
            renderAutocompleteList();
            return;
        }
        if (ev.key === 'ArrowUp') {
            ev.preventDefault();
            acIndex = (acIndex - 1 + acItems.length) % acItems.length;
            renderAutocompleteList();
            return;
        }
        if (ev.key === 'Enter' && !ev.shiftKey) {
            ev.preventDefault();
            applyAutocomplete(acItems[acIndex]);
            return;
        }
        if (ev.key === 'Escape') {
            ev.preventDefault();
            hideAutocomplete();
        }
    }

    function attachClauseAutocomplete(el) {
        el.addEventListener('input', function () { updateAutocomplete(el); });
        el.addEventListener('keydown', function (ev) { handleAutocompleteKey(ev, el); });
        el.addEventListener('blur', function () {
            setTimeout(hideAutocomplete, 180);
        });
        el.addEventListener('click', function () { updateAutocomplete(el); });
    }

    function syncCountOnlyUi() {
        const on = countOnlyInput && countOnlyInput.checked;
        if (topWrap) topWrap.style.opacity = on ? '0.45' : '';
        if (topWrap) topWrap.style.pointerEvents = on ? 'none' : '';
        columnsFilter.disabled = on || !tableSelect.value;
        columnsSelect.disabled = on || !tableSelect.value;
        btnColsAll.disabled = on || !tableSelect.value;
        btnColsNone.disabled = on || !tableSelect.value;
        if (columnsHint) {
            columnsHint.textContent = on
                ? 'Modo contagem: gera SELECT COUNT(1) AS total (colunas ignoradas).'
                : 'Ctrl+clique para varias colunas; duplo-clique na coluna insere no WHERE. Para COUNT, marque "Apenas contagem" acima.';
        }
    }

    if (countOnlyInput) {
        countOnlyInput.addEventListener('change', syncCountOnlyUi);
    }

    function formatHistoryDate(iso) {
        if (!iso) return '';
        const d = new Date(iso.replace(' ', 'T'));
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function updateExportLink(historyId) {
        lastHistoryId = historyId || null;
        if (!lastHistoryId) {
            exportBtn.hidden = true;
            return;
        }
        exportBtn.href = exportBase + '&export=xlsx&history_id=' + encodeURIComponent(String(lastHistoryId));
        exportBtn.hidden = false;
    }

    function renderHistory(items) {
        if (!items || !items.length) {
            historyList.innerHTML = '<li class="sql-history-empty">Nenhuma consulta executada ainda.</li>';
            return;
        }
        historyList.innerHTML = items.map(function (item) {
            const active = lastHistoryId && item.id === lastHistoryId ? ' active' : '';
            const sub = item.is_raw
                ? '<span class="hist-where">' + escapeHtml((item.sql || '').slice(0, 90)) + ((item.sql || '').length > 90 ? '…' : '') + '</span>'
                : '<span class="hist-where">' + escapeHtml(item.where_short || item.where || '') + '</span>' +
                  (item.order_by ? '<span class="hist-where">ORDER: ' + escapeHtml(item.order_by) + '</span>' : '');
            return '<li class="sql-history-row">' +
                '<button type="button" class="sql-history-item' + active + '" data-id="' + item.id + '" data-raw="' + (item.is_raw ? '1' : '0') + '">' +
                '<strong>' + escapeHtml(item.table) + '</strong>' +
                '<span class="hist-meta">' + item.row_count + ' linha(s) · ' + item.elapsed_ms + ' ms · ' +
                escapeHtml(formatHistoryDate(item.created_at)) + '</span>' +
                sub +
                '</button>' +
                '<button type="button" class="sql-history-delete" data-id="' + item.id + '" title="Excluir do historico" aria-label="Excluir">×</button>' +
                '</li>';
        }).join('');
    }

    function renderSaved(items) {
        if (!items || !items.length) {
            savedList.innerHTML = '<li class="sql-history-empty">Nenhuma query salva. Cole o SQL e use Salvar.</li>';
            return;
        }
        savedList.innerHTML = items.map(function (item) {
            return '<li class="sql-history-row">' +
                '<button type="button" class="sql-history-item sql-saved-item" data-id="' + item.id + '">' +
                '<strong>' + escapeHtml(item.title) + '</strong>' +
                '<span class="hist-meta">' + escapeHtml(formatHistoryDate(item.updated_at)) + '</span>' +
                '<span class="hist-where">' + escapeHtml(item.sql_short || '') + '</span>' +
                '</button>' +
                '<button type="button" class="sql-saved-delete" data-id="' + item.id + '" title="Excluir" aria-label="Excluir">×</button>' +
                '</li>';
        }).join('');
    }

    async function loadSaved() {
        if (!configured) {
            savedList.innerHTML = '<li class="sql-history-empty">Protheus nao configurado.</li>';
            return;
        }
        try {
            const data = await fetchJson(apiBase + '&protheus_sql_action=saved_queries');
            renderSaved(data.items || []);
        } catch (err) {
            savedList.innerHTML = '<li class="sql-history-empty">Erro: ' + escapeHtml(err.message) + '</li>';
        }
    }

    async function applySavedItem(id) {
        const data = await fetchJson(apiBase + '&protheus_sql_action=saved_query_item&id=' + encodeURIComponent(String(id)));
        const item = data.item;
        if (!item) return;
        rawText.value = item.sql || '';
        rawTitle.value = item.title || '';
        rawText.focus();
        closeSavedModal();
        setStatus('Query salva carregada: ' + (item.title || ''), 'ok');
    }

    async function deleteHistoryItem(id) {
        if (!id) return;
        if (!window.confirm('Excluir esta consulta do historico?')) return;

        const res = await fetch(apiBase + '&protheus_sql_action=history_delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id }),
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || ('HTTP ' + res.status));
        }
        if (lastHistoryId === id) {
            lastHistoryId = null;
            exportBtn.hidden = true;
        }
        await loadHistory();
    }

    async function loadHistory() {
        if (!configured) {
            historyList.innerHTML = '<li class="sql-history-empty">Protheus nao configurado.</li>';
            return;
        }
        try {
            const data = await fetchJson(apiBase + '&protheus_sql_action=history');
            renderHistory(data.items || []);
        } catch (err) {
            historyList.innerHTML = '<li class="sql-history-empty">Erro: ' + escapeHtml(err.message) + '</li>';
        }
    }

    async function applyHistoryItem(id) {
        const data = await fetchJson(apiBase + '&protheus_sql_action=history_item&id=' + encodeURIComponent(String(id)));
        const item = data.item;
        if (!item) return;

        if (item.is_raw || item.table === 'Query pronta') {
            rawText.value = item.sql || '';
            previewEl.textContent = item.sql || '';
            previewEl.hidden = !(item.sql || '');
            lastHistoryId = item.id;
            updateExportLink(item.id);
            historyList.querySelectorAll('.sql-history-item').forEach(function (btn) {
                btn.classList.toggle('active', parseInt(btn.dataset.id, 10) === item.id);
            });
            setStatus('Query pronta do historico carregada.', 'ok');
            closeHistoryModal();
            rawText.scrollIntoView({ behavior: 'smooth', block: 'start' });
            return;
        }

        tableFilter.value = item.table.substring(0, 4);
        await loadTables(tableFilter.value, item.table);
        await loadColumns(item.table);

        whereInput.value = item.where || '';
        orderByInput.value = item.order_by || '';
        document.getElementById('sql-top').value = String(item.top || 200);

        const isCount = item.is_count || item.columns === '__COUNT__';
        if (countOnlyInput) countOnlyInput.checked = isCount;
        syncCountOnlyUi();

        const cols = (item.columns || '*').trim();
        Array.from(columnsSelect.options).forEach(function (o) { o.selected = false; });
        if (isCount) {
            /* colunas ignoradas no modo COUNT */
        } else if (cols === '*' || cols === '') {
            const star = columnsSelect.querySelector('option[value="*"]');
            if (star) star.selected = true;
        } else {
            cols.split(',').map(function (c) { return c.trim(); }).forEach(function (name) {
                const opt = Array.from(columnsSelect.options).find(function (o) { return o.value === name; });
                if (opt) opt.selected = true;
            });
        }

        lastHistoryId = item.id;
        updateExportLink(item.id);
        previewEl.textContent = item.sql || '';
        previewEl.hidden = !(item.sql || '');

        historyList.querySelectorAll('.sql-history-item').forEach(function (btn) {
            btn.classList.toggle('active', parseInt(btn.dataset.id, 10) === item.id);
        });
        closeHistoryModal();
    }

    function renderResults(data) {
        cellFullValues.clear();
        const cols = data.columns || [];
        const rows = data.rows || [];

        headEl.innerHTML = '<tr><th class="sql-col-num">#</th>' + cols.map(function (c) {
            return '<th title="' + escapeAttr(c) + '">' + escapeHtml(c) + '</th>';
        }).join('') + '</tr>';

        bodyEl.innerHTML = rows.map(function (row, rowIndex) {
            const num = rowIndex + 1;
            return '<tr><td class="sql-col-num">' + num + '</td>' + cols.map(function (c) {
                return renderCellHtml(c, row, rowIndex);
            }).join('') + '</tr>';
        }).join('');

        const hasData = rows.length > 0 || cols.length > 0;
        wrapEl.hidden = !hasData;
        resultsToolbar.hidden = !hasData;
        resultsCount.textContent = rows.length
            ? rows.length + ' registro(s) exibido(s) — linhas #1 a #' + rows.length
            : '';
    }

    function openCellFromTd(td) {
        const key = td.dataset.cellKey || '';
        openCellModal(td.dataset.col || 'Campo', cellFullValues.get(key) || '');
    }

    bodyEl.addEventListener('click', function (ev) {
        const td = ev.target.closest('.sql-cell-truncated');
        if (!td || !bodyEl.contains(td)) return;
        openCellFromTd(td);
    });

    bodyEl.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' && ev.key !== ' ') return;
        const td = ev.target.closest('.sql-cell-truncated');
        if (!td) return;
        ev.preventDefault();
        openCellFromTd(td);
    });

    cellModalClose.addEventListener('click', closeCellModal);
    cellModal.querySelectorAll('[data-close-cell-modal]').forEach(function (el) {
        el.addEventListener('click', closeCellModal);
    });

    if (historyOpen) {
        historyOpen.addEventListener('click', openHistoryModal);
    }
    if (historyClose) {
        historyClose.addEventListener('click', closeHistoryModal);
    }
    historyModal.querySelectorAll('[data-close-history-modal]').forEach(function (el) {
        el.addEventListener('click', closeHistoryModal);
    });

    if (savedOpen) {
        savedOpen.addEventListener('click', openSavedModal);
    }
    if (savedClose) {
        savedClose.addEventListener('click', closeSavedModal);
    }
    savedModal.querySelectorAll('[data-close-saved-modal]').forEach(function (el) {
        el.addEventListener('click', closeSavedModal);
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') return;
        if (!historyModal.hidden) {
            closeHistoryModal();
            return;
        }
        if (!savedModal.hidden) {
            closeSavedModal();
            return;
        }
        if (!cellModal.hidden) closeCellModal();
    });

    async function cancelOnServer(queryId) {
        if (!queryId) return;
        try {
            await fetch(apiBase + '&protheus_sql_action=cancel', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query_id: queryId }),
            });
        } catch (e) { /* ignore */ }
    }

    tableFilter.addEventListener('input', debounce(function () {
        loadTables(tableFilter.value, null);
    }, 350));

    tableSelect.addEventListener('change', function () {
        loadColumns(tableSelect.value);
        syncCountOnlyUi();
    });

    columnsFilter.addEventListener('input', function () {
        if (!allColumnOptions.length) return;
        renderColumnOptions(allColumnOptions, columnsFilter.value);
    });

    btnColsAll.addEventListener('click', function () {
        Array.from(columnsSelect.options).forEach(function (o) { o.selected = false; });
        const star = columnsSelect.querySelector('option[value="*"]');
        if (star) star.selected = true;
    });

    btnColsNone.addEventListener('click', function () {
        Array.from(columnsSelect.options).forEach(function (o) { o.selected = false; });
    });

    btnStop.addEventListener('click', function () {
        if (abortController) abortController.abort();
        cancelOnServer(activeQueryId);
        setStatus('Cancelamento solicitado…', 'run');
    });

    form.addEventListener('submit', async function (ev) {
        ev.preventDefault();
        if (abortController) abortController.abort();

        const table = tableSelect.value.trim();
        if (!table) {
            setStatus('Selecione uma tabela.', 'err');
            return;
        }

        const queryId = crypto.randomUUID().replace(/-/g, '');
        activeQueryId = queryId;
        abortController = new AbortController();

        const countOnly = countOnlyInput && countOnlyInput.checked;
        const payload = {
            query_id: queryId,
            table: table,
            where: whereInput.value.trim(),
            order_by: normalizeOrderByClient(orderByInput.value),
            columns: countOnly ? '__COUNT__' : getSelectedColumnsValue(),
            top: parseInt(document.getElementById('sql-top').value, 10) || 200,
            count_only: countOnly,
        };

        setRunning(true);
        setStatus('Executando consulta…', 'run');
        previewEl.hidden = true;
        wrapEl.hidden = true;
        resultsToolbar.hidden = true;
        exportBtn.hidden = true;
        headEl.innerHTML = '';
        bodyEl.innerHTML = '';

        try {
            const res = await fetch(apiBase + '&protheus_sql_action=run', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                signal: abortController.signal,
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.error || ('HTTP ' + res.status));
            }
            previewEl.textContent = data.sql || '';
            previewEl.hidden = false;
            renderResults(data);
            if (data.history_id) {
                updateExportLink(data.history_id);
                loadHistory();
            }
            let msg = data.row_count + ' linha(s) em ' + data.elapsed_ms + ' ms';
            if (data.truncated) msg += ' — limite TOP atingido.';
            setStatus(msg, 'ok');
        } catch (err) {
            if (err.name === 'AbortError') {
                setStatus('Consulta interrompida pelo usuario.', 'err');
            } else {
                setStatus('Erro: ' + (err.message || String(err)), 'err');
            }
        } finally {
            activeQueryId = null;
            abortController = null;
            setRunning(false);
        }
    });

    historyList.addEventListener('click', function (ev) {
        const delBtn = ev.target.closest('.sql-history-delete');
        if (delBtn && delBtn.dataset.id) {
            ev.preventDefault();
            ev.stopPropagation();
            deleteHistoryItem(parseInt(delBtn.dataset.id, 10)).catch(function (err) {
                setStatus('Erro ao excluir: ' + err.message, 'err');
            });
            return;
        }
        const btn = ev.target.closest('.sql-history-item');
        if (!btn || !btn.dataset.id) return;
        applyHistoryItem(parseInt(btn.dataset.id, 10)).catch(function (err) {
            setStatus('Erro ao carregar historico: ' + err.message, 'err');
        });
    });

    historyRefresh.addEventListener('click', function () {
        loadHistory();
    });

    savedRefresh.addEventListener('click', function () {
        loadSaved();
    });

    savedList.addEventListener('click', function (ev) {
        const delBtn = ev.target.closest('.sql-saved-delete');
        if (delBtn && delBtn.dataset.id) {
            ev.preventDefault();
            ev.stopPropagation();
            if (!window.confirm('Excluir esta query salva?')) return;
            fetch(apiBase + '&protheus_sql_action=saved_query_delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(delBtn.dataset.id, 10) }),
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data.ok) throw new Error(data.error || 'Erro');
                loadSaved();
            }).catch(function (err) {
                setStatus('Erro ao excluir: ' + err.message, 'err');
            });
            return;
        }
        const btn = ev.target.closest('.sql-saved-item');
        if (!btn || !btn.dataset.id) return;
        applySavedItem(parseInt(btn.dataset.id, 10)).catch(function (err) {
            setStatus('Erro: ' + err.message, 'err');
        });
    });

    btnRawClear.addEventListener('click', function () {
        rawText.value = '';
        rawTitle.value = '';
    });

    btnRawSave.addEventListener('click', async function () {
        const sql = rawText.value.trim();
        if (!sql) {
            setStatus('Cole o SQL antes de salvar.', 'err');
            return;
        }
        try {
            const res = await fetch(apiBase + '&protheus_sql_action=saved_query_save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: rawTitle.value.trim(), sql: sql }),
            });
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
            setStatus('Query salva na biblioteca.', 'ok');
            loadSaved();
        } catch (err) {
            setStatus('Erro ao salvar: ' + err.message, 'err');
        }
    });

    btnRawRun.addEventListener('click', async function () {
        if (abortController) abortController.abort();

        const sql = rawText.value.trim();
        if (!sql) {
            setStatus('Cole a query SQL.', 'err');
            return;
        }

        const queryId = crypto.randomUUID().replace(/-/g, '');
        activeQueryId = queryId;
        abortController = new AbortController();

        setRunning(true);
        setStatus('Executando query pronta…', 'run');
        previewEl.hidden = true;
        wrapEl.hidden = true;
        resultsToolbar.hidden = true;
        exportBtn.hidden = true;
        headEl.innerHTML = '';
        bodyEl.innerHTML = '';

        try {
            const res = await fetch(apiBase + '&protheus_sql_action=run_raw', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query_id: queryId, sql: sql }),
                signal: abortController.signal,
            });
            const data = await res.json();
            if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP ' + res.status));
            previewEl.textContent = data.sql || '';
            previewEl.hidden = false;
            renderResults(data);
            if (data.history_id) {
                updateExportLink(data.history_id);
                loadHistory();
            }
            let msg = data.row_count + ' linha(s) em ' + data.elapsed_ms + ' ms';
            if (data.truncated) msg += ' — limite TOP atingido.';
            setStatus(msg, 'ok');
        } catch (err) {
            if (err.name === 'AbortError') {
                setStatus('Consulta interrompida.', 'err');
            } else {
                setStatus('Erro: ' + (err.message || String(err)), 'err');
            }
        } finally {
            activeQueryId = null;
            abortController = null;
            setRunning(false);
        }
    });

    columnsSelect.addEventListener('dblclick', function () {
        const opt = columnsSelect.selectedOptions[0];
        if (!opt || !opt.value || opt.value === '*') return;
        insertIntoClause(whereInput, opt.value);
    });

    autocompleteEl.addEventListener('mousedown', function (ev) {
        ev.preventDefault();
        const btn = ev.target.closest('.sql-autocomplete-item');
        if (btn && btn.dataset.name) {
            applyAutocomplete(btn.dataset.name);
        }
    });

    attachClauseAutocomplete(whereInput);
    attachClauseAutocomplete(orderByInput);

    if (configured) {
        tableFilter.value = 'ZA4';
        loadTables('ZA4', defaultTable);
        loadHistory();
        loadSaved();
    } else {
        historyList.innerHTML = '<li class="sql-history-empty">Configure o Protheus.</li>';
        savedList.innerHTML = '<li class="sql-history-empty">Configure o Protheus.</li>';
    }
})();
</script>
