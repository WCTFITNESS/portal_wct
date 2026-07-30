<?php

declare(strict_types=1);

use App\Services\SswTrackingService;

/** @var SswTrackingService $ssw */
$ssw = $app['sswTrackingService'];

$feedback = null;
$feedbackClass = 'ok';
$result = null;
$apiDetail = null;

$mode = trim((string) ($_POST['mode'] ?? $_GET['mode'] ?? 'remetente'));
if (!in_array($mode, ['remetente', 'dias', 'api'], true)) {
    $mode = 'remetente';
}

$form = [
    'cnpj' => preg_replace('/\D+/', '', (string) ($_POST['cnpj'] ?? $ssw->defaultCnpj())) ?: $ssw->defaultCnpj(),
    'senha' => (string) ($_POST['senha'] ?? ''),
    'documents' => trim((string) ($_POST['documents'] ?? '')),
    'dias' => max(1, min(30, (int) ($_POST['dias'] ?? 10))),
    'api_tipo' => trim((string) ($_POST['api_tipo'] ?? 'nro_nf')),
    'api_valor' => trim((string) ($_POST['api_valor'] ?? '')),
];

$senhaEfetiva = trim($form['senha']) !== '' ? $form['senha'] : $ssw->defaultSenha();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string) ($_POST['form_type'] ?? '') === 'ssw_track') {
    try {
        if ($mode === 'dias') {
            $result = $ssw->trackBySenderDays($form['cnpj'], $form['dias'], $senhaEfetiva !== '' ? $senhaEfetiva : null);
        } elseif ($mode === 'api') {
            $apiDetail = $ssw->trackViaApi(
                $form['cnpj'],
                $form['api_tipo'],
                $form['api_valor'],
                $senhaEfetiva !== '' ? $senhaEfetiva : null
            );
            if (!$apiDetail['success']) {
                $feedback = $apiDetail['message'] ?: 'Documento não localizado na API SSW.';
                $feedbackClass = 'err';
            } else {
                $feedback = 'Consulta API OK.';
            }
        } else {
            $result = $ssw->trackBySenderDocuments(
                $form['cnpj'],
                $form['documents'],
                $senhaEfetiva !== '' ? $senhaEfetiva : null
            );
        }

        if (is_array($result) && $result['rows'] === [] && !empty($result['raw_message'])) {
            $feedback = (string) $result['raw_message'];
            $feedbackClass = 'err';
        } elseif (is_array($result) && $result['rows'] !== []) {
            $feedback = count($result['rows']) . ' registro(s) encontrado(s) no SSW.';
        }
    } catch (Throwable $e) {
        $feedback = $e->getMessage();
        $feedbackClass = 'err';
    }
}

$cnpjMasked = $form['cnpj'];
if (strlen($cnpjMasked) === 14) {
    $cnpjMasked = substr($cnpjMasked, 0, 2) . '.' . substr($cnpjMasked, 2, 3) . '.' . substr($cnpjMasked, 5, 3)
        . '/' . substr($cnpjMasked, 8, 4) . '-' . substr($cnpjMasked, 12, 2);
}

$pageUrl = portal_wct_public_path($baseUrl, 'index.php?page=rastreamento-ssw');

?>
<style>
    .ssw-page h1 { margin-bottom: 6px; }
    .ssw-lead { color: #64748b; margin: 0 0 16px; }
    .ssw-tabs {
        display: inline-flex;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 16px;
    }
    .ssw-tabs a {
        padding: 8px 14px;
        text-decoration: none;
        color: #334155;
        font-weight: 600;
        font-size: .85rem;
        background: #fff;
    }
    .ssw-tabs a.active { background: #111; color: #f5b700; }
    .ssw-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px 16px;
        max-width: 820px;
    }
    .ssw-grid .full { grid-column: 1 / -1; }
    .ssw-hint { font-size: .82rem; color: #64748b; margin-top: 4px; font-weight: normal; }
    .ssw-meta {
        margin: 14px 0 8px;
        color: #334155;
        font-size: .92rem;
    }
    .ssw-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .ssw-table th, .ssw-table td {
        border: 1px solid #e5e7eb;
        padding: 10px;
        vertical-align: top;
        text-align: left;
        font-size: .9rem;
    }
    .ssw-table th { background: #f8fafc; }
    .ssw-sit-title {
        color: #dc2626;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .02em;
        margin: 0 0 4px;
    }
    .ssw-sit-detail { color: #334155; white-space: pre-wrap; }
    .ssw-open {
        display: inline-block;
        margin-top: 6px;
        font-size: .82rem;
        font-weight: 600;
    }
    .ssw-api-pre {
        background: #0f172a;
        color: #e2e8f0;
        padding: 12px;
        border-radius: 8px;
        overflow: auto;
        max-height: 480px;
        font-size: .8rem;
    }
    .ssw-ext {
        margin-top: 18px;
        padding-top: 14px;
        border-top: 1px solid #e5e7eb;
        font-size: .85rem;
        color: #64748b;
    }
    @media (max-width: 800px) {
        .ssw-grid { grid-template-columns: 1fr; }
    }
</style>

<section class="card ssw-page">
    <h1>Rastreamento SSW</h1>
    <p class="ssw-lead">
        Consulta no <a href="https://ssw.inf.br/2/rastreamento" target="_blank" rel="noopener">ssw.inf.br</a>
        com remetente padrão <strong>WCT</strong> (CNPJ <?= htmlspecialchars($cnpjMasked, ENT_QUOTES, 'UTF-8') ?>).
    </p>

    <?php if ($feedback): ?>
        <div class="msg <?= htmlspecialchars($feedbackClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="ssw-tabs">
        <a class="<?= $mode === 'remetente' ? 'active' : '' ?>" href="<?= htmlspecialchars($pageUrl . '&mode=remetente', ENT_QUOTES, 'UTF-8') ?>">Pelo remetente</a>
        <a class="<?= $mode === 'dias' ? 'active' : '' ?>" href="<?= htmlspecialchars($pageUrl . '&mode=dias', ENT_QUOTES, 'UTF-8') ?>">Últimos dias</a>
        <a class="<?= $mode === 'api' ? 'active' : '' ?>" href="<?= htmlspecialchars($pageUrl . '&mode=api', ENT_QUOTES, 'UTF-8') ?>">API detalhe</a>
    </div>

    <form method="post" action="<?= htmlspecialchars($pageUrl . '&mode=' . rawurlencode($mode), ENT_QUOTES, 'UTF-8') ?>" class="ssw-grid">
        <input type="hidden" name="form_type" value="ssw_track">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode, ENT_QUOTES, 'UTF-8') ?>">

        <label>CNPJ remetente (WCT)
            <input type="text" name="cnpj" value="<?= htmlspecialchars($form['cnpj'], ENT_QUOTES, 'UTF-8') ?>" maxlength="18" required>
            <div class="ssw-hint">Pré-preenchido com o CNPJ da WCT. Altere só se precisar de outro remetente.</div>
        </label>

        <label>Senha SSW <?= $ssw->hasConfiguredSenha() ? '(opcional — já há senha no servidor)' : '(da transportadora)' ?>
            <input type="password" name="senha" value="" autocomplete="off" placeholder="<?= $ssw->hasConfiguredSenha() ? 'Deixe em branco para usar a configurada' : 'Informe a senha SSW' ?>">
            <div class="ssw-hint">Com senha, a situação completa e comprovantes ficam disponíveis.</div>
        </label>

        <?php if ($mode === 'remetente'): ?>
            <label class="full">N Fiscais / N Pedidos / N Coletas *
                <textarea name="documents" rows="6" required placeholder="Um por linha, ex.&#10;1571254"><?= htmlspecialchars($form['documents'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <div class="ssw-hint">Até 100 NFs (ou 50 pedidos/coletas). Disponível por até 90 dias após coleta/entrega.</div>
            </label>
        <?php elseif ($mode === 'dias'): ?>
            <label>Considerar últimos
                <select name="dias">
                    <?php for ($d = 1; $d <= 30; $d++): ?>
                        <option value="<?= $d ?>" <?= $form['dias'] === $d ? 'selected' : '' ?>><?= $d ?> dia<?= $d > 1 ? 's' : '' ?></option>
                    <?php endfor; ?>
                </select>
                <div class="ssw-hint">Lista todos os documentos do remetente no período (exige senha).</div>
            </label>
            <div></div>
        <?php else: ?>
            <label>Tipo
                <select name="api_tipo">
                    <option value="nro_nf" <?= $form['api_tipo'] === 'nro_nf' ? 'selected' : '' ?>>Nº NF</option>
                    <option value="pedido" <?= $form['api_tipo'] === 'pedido' ? 'selected' : '' ?>>Pedido</option>
                    <option value="chave_nfe" <?= $form['api_tipo'] === 'chave_nfe' ? 'selected' : '' ?>>Chave NF-e</option>
                    <option value="nro_coleta" <?= $form['api_tipo'] === 'nro_coleta' ? 'selected' : '' ?>>Nº Coleta</option>
                </select>
            </label>
            <label>Documento *
                <input type="text" name="api_valor" required value="<?= htmlspecialchars($form['api_valor'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex.: 1571254">
            </label>
        <?php endif; ?>

        <div class="full">
            <button type="submit">Rastrear no SSW</button>
        </div>
    </form>

    <?php if (is_array($result)): ?>
        <div class="ssw-meta">
            Remetente: <strong><?= htmlspecialchars((string) $result['remetente_label'], ENT_QUOTES, 'UTF-8') ?></strong>
            · Modo: <?= $result['mode'] === 'remetente_dias' ? 'últimos dias' : 'pelo remetente' ?>
        </div>

        <?php if ($result['rows'] !== []): ?>
            <div style="overflow-x:auto;">
                <table class="ssw-table">
                    <thead>
                        <tr>
                            <th style="width:120px;">N Fiscal / Coleta<br>N Pedido</th>
                            <th style="width:180px;">Unidade<br>Data/hora</th>
                            <th>Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['rows'] as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string) $row['documento'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if ($row['pedido'] !== ''): ?>
                                        <div class="ssw-hint"><?= htmlspecialchars((string) $row['pedido'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['unidade'] !== ''): ?>
                                        <div><?= htmlspecialchars((string) $row['unidade'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if ($row['data_hora'] !== ''): ?>
                                        <div class="ssw-hint"><?= htmlspecialchars((string) $row['data_hora'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['situacao_titulo'] !== ''): ?>
                                        <p class="ssw-sit-title"><?= htmlspecialchars((string) $row['situacao_titulo'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php endif; ?>
                                    <?php if ($row['situacao_detalhe'] !== '' && $row['situacao_detalhe'] !== $row['situacao_titulo']): ?>
                                        <div class="ssw-sit-detail"><?= nl2br(htmlspecialchars((string) $row['situacao_detalhe'], ENT_QUOTES, 'UTF-8')) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['detalhe_url'])): ?>
                                        <a class="ssw-open" href="<?= htmlspecialchars((string) $row['detalhe_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Mais detalhes no SSW</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (is_array($apiDetail) && !empty($apiDetail['data'])): ?>
        <h2 style="margin-top:18px;font-size:1.1rem;">Resposta API</h2>
        <pre class="ssw-api-pre"><?= htmlspecialchars(json_encode($apiDetail['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', ENT_QUOTES, 'UTF-8') ?></pre>
    <?php endif; ?>

    <div class="ssw-ext">
        Atalhos no site SSW:
        <a href="https://ssw.inf.br/2/rastreamento" target="_blank" rel="noopener">Pelo remetente</a>
        ·
        <a href="https://ssw.inf.br/2/ssw_rastreamento?pwd=1" target="_blank" rel="noopener">Últimos 30 dias</a>
        <?php if (!$ssw->hasConfiguredSenha()): ?>
            <br>Dica: configure <code>PORTAL_SSW_SENHA</code> no ambiente (Render) para não digitar a senha toda vez.
        <?php endif; ?>
    </div>
</section>
