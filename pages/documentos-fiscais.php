<?php

declare(strict_types=1);

use App\Services\FiscalDocsService;
use App\Services\MlInvoiceBatchService;

/** @var FiscalDocsService $central */
$central = $app['fiscalDocsService'];
$repo = $central->repository();
$cteService = $app['sefazCteDistribuicaoService'];
$nfeService = $app['sefazNfeDistribuicaoService'];
$rockit = $app['rockitService'];
$isPortalAdmin = !empty($currentPortalUser['is_admin']);

$feedback = [];
$feedbackClass = 'ok';
if (isset($_GET['flash_err']) && $_GET['flash_err'] !== '') {
    $feedback[] = (string) $_GET['flash_err'];
    $feedbackClass = 'err';
}

try {
    $copied = $central->backfillCteIfNeeded();
    if ($copied > 0) {
        $feedback[] = $copied . ' CT-e já baixados foram trazidos para a central.';
    }
} catch (Throwable $e) {
    $feedback[] = 'Não foi possível copiar os CT-e antigos: ' . $e->getMessage();
    $feedbackClass = 'err';
}

$mlTiposPadrao = ['full_inbound', 'full_retorno', 'full_retirada'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formType = (string) ($_POST['form_type'] ?? '');
    ignore_user_abort(true);
    @set_time_limit(600);
    try {
        if ($formType === 'fd_sync_sefaz') {
            $lines = [];
            foreach ($nfeService->syncAll(90) as $r) {
                $lines[] = 'NF-e ' . $r['empresa'] . ': ' . $r['mensagem'] . ' (' . $r['novos'] . ' novas, '
                    . $r['completos'] . ' XML completos, ' . $r['ciencias'] . ' ciências)';
            }
            foreach ($cteService->syncAll(90) as $r) {
                $lines[] = 'CT-e ' . $r['empresa'] . ': ' . $r['mensagem'] . ' (' . $r['novos'] . ' novos)';
            }
            if ($lines === []) {
                $lines[] = 'Nenhuma empresa com certificado válido.';
                $feedbackClass = 'err';
            }
            $feedback = array_merge($feedback, $lines);
        }

        if ($formType === 'fd_import_ml') {
            $tipos = is_array($_POST['ml_tipos'] ?? null) ? array_map('strval', $_POST['ml_tipos']) : [];
            $r = $central->importMl((string) ($_POST['ml_de'] ?? ''), (string) ($_POST['ml_ate'] ?? ''), $tipos);
            $feedback[] = 'Mercado Livre: ' . $r['novos'] . ' documentos novos, ' . $r['existentes'] . ' já estavam na central'
                . ($r['atualizados'] > 0 ? ', ' . $r['atualizados'] . ' resumos completados' : '') . '.';
        }

        if ($formType === 'fd_import_rockit') {
            $r = $central->importRockit((string) ($_POST['cb_de'] ?? ''), (string) ($_POST['cb_ate'] ?? ''));
            $feedback[] = 'Casas Bahia: ' . $r['pedidos'] . ' pedidos, ' . $r['novos'] . ' documentos novos, '
                . $r['existentes'] . ' já estavam na central.' . ($r['aviso'] !== '' ? ' ' . $r['aviso'] : '');
        }

        if ($formType === 'fd_retry_ciencia') {
            if (!$isPortalAdmin) {
                throw new RuntimeException('Somente administradores.');
            }
            $n = $repo->retryManifestErrors();
            $feedback[] = $n . ' nota(s) voltaram para a fila da Ciência da Operação. Ela é enviada na próxima busca na SEFAZ.';
        }
    } catch (Throwable $e) {
        $feedback[] = $e->getMessage();
        $feedbackClass = 'err';
    }
}

$profiles = $cteService->listProfilesStatus();
$anyCert = false;
foreach ($profiles as $p) {
    if ($p['has_cert'] && !$p['cert_expired']) {
        $anyCert = true;
    }
}
$manifest = $repo->manifestCounts();
$rockitConfigured = $rockit->getStatus()['configured'];

$filters = [
    'origem' => (string) ($_GET['origem'] ?? ''),
    'tipo' => (string) ($_GET['tipo'] ?? ''),
    'propria' => (string) ($_GET['propria'] ?? ''),
    'situacao' => (string) ($_GET['situacao'] ?? ''),
    'de' => (string) ($_GET['de'] ?? date('Y-m-d', strtotime('-90 days'))),
    'ate' => (string) ($_GET['ate'] ?? date('Y-m-d')),
    'busca' => trim((string) ($_GET['busca'] ?? '')),
];
$listLimit = 500;
$total = $repo->countDocuments($filters);
$docs = $repo->listDocuments($filters, $listLimit);

$pageUrl = portal_wct_public_path($baseUrl, 'index.php?page=documentos-fiscais');
$zipAllUrl = portal_wct_public_path(
    $baseUrl,
    'index.php?' . http_build_query(['page' => 'documentos-fiscais', 'download' => 'zip', 'todos' => '1'] + $filters)
);
$fmtDate = static fn ($v): string => $v ? date('d/m/Y H:i', strtotime((string) $v)) : '—';
$fmtDoc = static function ($v): string {
    $d = preg_replace('/\D/', '', (string) $v) ?? '';
    if (strlen($d) === 14) {
        return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2);
    }
    if (strlen($d) === 11) {
        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
    }

    return (string) $v;
};
$tipoLabel = static function (array $d): string {
    return match ((string) $d['tipo']) {
        'nfe' => (string) ($d['modelo'] ?? '') === '65' ? 'NFC-e' : 'NF-e',
        'nfe_resumo' => 'NF-e (resumo)',
        'cte' => (string) ($d['modelo'] ?? '') === '67' ? 'CT-e OS' : 'CT-e',
        'evento' => (string) ($d['desc_evento'] ?: 'Evento ' . $d['tp_evento']),
        default => (string) $d['tipo'],
    };
};
$selected = static fn (string $a, string $b): string => $a === $b ? ' selected' : '';
?>
<style>
    .fd-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
    .fd-hint { color: #64748b; font-size: .85rem; margin: 6px 0 0; }
    .fd-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .fd-actions button, .fd-actions a.fd-btn { width: auto; }
    a.fd-btn { display: inline-block; margin-top: 16px; padding: 10px 16px; border: 1px solid #f5b700; border-radius: 6px; background: #fff; color: #111; font-weight: bold; text-decoration: none; text-transform: uppercase; font-size: .85rem; }
    .fd-table-wrap { overflow-x: auto; max-height: 70vh; }
    .fd-table th { position: sticky; top: 0; background: #f1f5f9; white-space: nowrap; font-size: .75rem; text-transform: uppercase; }
    .fd-table td { font-size: .82rem; vertical-align: top; }
    .fd-table input[type=checkbox] { width: auto; margin: 0; }
    .fd-tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .72rem; background: #e2e8f0; white-space: nowrap; }
    .fd-tag.nfe { background: #dbeafe; }
    .fd-tag.cte { background: #dcfce7; }
    .fd-tag.evt { background: #fef3c7; }
    .fd-tag.res { background: #ede9fe; }
    .fd-tag.bad { background: #fee2e2; color: #991b1b; }
    .fd-tag.own { background: #f1f5f9; border: 1px solid #cbd5e1; }
    .fd-warn { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; padding: 10px 12px; border-radius: 8px; margin: 10px 0; }
    .fd-box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-top: 12px; }
    .fd-box h3 { margin: 0 0 4px; font-size: 1rem; }
    .fd-checks { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 8px; }
    .fd-checks label { display: flex; align-items: center; gap: 6px; font-weight: normal; margin: 0; }
    .fd-checks input { width: auto; margin: 0; }
    .fd-feedback li { margin: 2px 0; }
</style>

<section class="card">
    <h1>Documentos fiscais</h1>
    <p class="fd-hint" style="margin-top:0">
        Tudo num lugar só: NF-e que outras empresas emitiram para a WCT e CT-e (direto da SEFAZ, com o certificado A1),
        mais as notas do Full do Mercado Livre e da Casas Bahia. A SEFAZ entrega só os <strong>últimos 3 meses</strong>
        e não devolve as notas que a própria WCT emitiu.
    </p>

    <?php if ($feedback !== []): ?>
        <div class="msg <?= htmlspecialchars($feedbackClass) ?>">
            <ul class="fd-feedback" style="margin:0;padding-left:18px">
                <?php foreach ($feedback as $line): ?><li><?= htmlspecialchars($line) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!$anyCert): ?>
        <div class="fd-warn">
            Nenhum certificado A1 válido carregado.
            <?php if ($isPortalAdmin): ?>
                Carregue em <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=sefaz-cte-dfe')) ?>">CT-e SEFAZ → Certificados e empresas</a>.
            <?php else: ?>
                Peça a um administrador do portal para carregar.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="fd-table-wrap" style="max-height:none">
            <table class="fd-table">
                <thead><tr><th>Empresa</th><th>NF-e (SEFAZ)</th><th>CT-e (SEFAZ)</th></tr></thead>
                <tbody>
                <?php foreach ($profiles as $p): ?>
                    <?php
                    $nfeNext = !empty($p['nfe_next_sync_at']) ? strtotime((string) $p['nfe_next_sync_at']) : false;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string) $p['label']) ?></strong><br><small><?= htmlspecialchars($fmtDoc($p['cnpj'])) ?></small>
                            <?php if (!$p['has_cert']): ?><br><span class="fd-tag bad">sem certificado</span><?php endif; ?>
                            <?php if ($p['cert_expired']): ?><br><span class="fd-tag bad">certificado vencido</span><?php endif; ?>
                        </td>
                        <td>
                            Última busca: <?= htmlspecialchars($fmtDate($p['nfe_last_sync_at'] ?? null)) ?>
                            <?php if ($nfeNext !== false && $nfeNext > time()): ?><br><small class="fd-hint">próxima liberada às <?= date('d/m H:i', $nfeNext) ?></small><?php endif; ?>
                            <?php if (!empty($p['nfe_last_status'])): ?><br><small class="fd-hint"><?= htmlspecialchars((string) $p['nfe_last_status']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            Última busca: <?= htmlspecialchars($fmtDate($p['last_sync_at'] ?? null)) ?>
                            <?php if (!$p['can_sync_now'] && $p['next_sync_ts']): ?><br><small class="fd-hint">próxima liberada às <?= date('d/m H:i', (int) $p['next_sync_ts']) ?></small><?php endif; ?>
                            <?php if (!empty($p['last_status'])): ?><br><small class="fd-hint"><?= htmlspecialchars((string) $p['last_status']) ?></small><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" class="fd-actions" data-fd-loading="Consultando a SEFAZ (NF-e e CT-e)... pode levar alguns minutos.">
            <input type="hidden" name="form_type" value="fd_sync_sefaz">
            <button type="submit">Buscar na SEFAZ (NF-e e CT-e de todas as empresas)</button>
        </form>
        <p class="fd-hint">
            Para as NF-e recebidas, o portal registra automaticamente a <strong>Ciência da Operação</strong> (só informa que a WCT
            sabe da nota, não confirma nem recusa). O XML completo chega numa busca seguinte (a SEFAZ libera nova consulta a cada 1 hora).
            Ciência: <?= (int) ($manifest['ciencia'] ?? 0) ?> registradas, <?= (int) ($manifest['pendente'] ?? 0) ?> na fila,
            <?= (int) ($manifest['erro'] ?? 0) ?> recusadas.
        </p>
        <?php if ($isPortalAdmin && (int) ($manifest['erro'] ?? 0) > 0): ?>
            <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" class="fd-actions">
                <input type="hidden" name="form_type" value="fd_retry_ciencia">
                <button type="submit">Tentar de novo a Ciência das recusadas</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <details style="margin-top:14px">
        <summary style="cursor:pointer;color:#2563eb">Trazer notas do Mercado Livre e da Casas Bahia para a central</summary>

        <div class="fd-box">
            <h3>Mercado Livre</h3>
            <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" data-fd-loading="Baixando notas do Mercado Livre...">
                <input type="hidden" name="form_type" value="fd_import_ml">
                <div class="fd-grid">
                    <div><label>Emissão de</label><input type="date" name="ml_de" value="<?= date('Y-m-d', strtotime('-30 days')) ?>"></div>
                    <div><label>Emissão até</label><input type="date" name="ml_ate" value="<?= date('Y-m-d') ?>"></div>
                </div>
                <div class="fd-checks">
                    <?php foreach (MlInvoiceBatchService::TIPOS as $key => $tipo): ?>
                        <label><input type="checkbox" name="ml_tipos[]" value="<?= htmlspecialchars($key) ?>"<?= in_array($key, $mlTiposPadrao, true) ? ' checked' : '' ?>> <?= htmlspecialchars($tipo['label']) ?></label>
                    <?php endforeach; ?>
                </div>
                <p class="fd-hint">Até 31 dias por vez.</p>
                <div class="fd-actions"><button type="submit">Importar do Mercado Livre</button></div>
            </form>
        </div>

        <div class="fd-box">
            <h3>Casas Bahia (Full)</h3>
            <?php if (!$rockitConfigured): ?>
                <p class="fd-hint">Cadastre o login da Rock.IT em
                    <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=casasbahia-full')) ?>">Notas Full Casas Bahia</a>.</p>
            <?php else: ?>
                <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" data-fd-loading="Baixando notas da Casas Bahia...">
                    <input type="hidden" name="form_type" value="fd_import_rockit">
                    <div class="fd-grid">
                        <div><label>Compra de</label><input type="date" name="cb_de" value="<?= date('Y-m-d', strtotime('-30 days')) ?>"></div>
                        <div><label>Compra até</label><input type="date" name="cb_ate" value="<?= date('Y-m-d') ?>"></div>
                    </div>
                    <p class="fd-hint">Traz as notas emitidas e os cancelamentos dos pedidos do período. Empresa consultada: <?= htmlspecialchars($rockit->currentCompanyLabel()) ?>.</p>
                    <div class="fd-actions"><button type="submit">Importar da Casas Bahia</button></div>
                </form>
            <?php endif; ?>
        </div>
    </details>
</section>

<section class="card">
    <h2 style="margin-top:0">Pesquisa</h2>
    <form method="get" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php')) ?>">
        <input type="hidden" name="page" value="documentos-fiscais">
        <div class="fd-grid">
            <div><label>Emissão de</label><input type="date" name="de" value="<?= htmlspecialchars($filters['de']) ?>"></div>
            <div><label>Emissão até</label><input type="date" name="ate" value="<?= htmlspecialchars($filters['ate']) ?>"></div>
            <div>
                <label>Tipo</label>
                <select name="tipo">
                    <option value="">Todos</option>
                    <option value="nfe"<?= $selected($filters['tipo'], 'nfe') ?>>NF-e</option>
                    <option value="cte"<?= $selected($filters['tipo'], 'cte') ?>>CT-e</option>
                    <option value="evento"<?= $selected($filters['tipo'], 'evento') ?>>Eventos (cancelamento, correção...)</option>
                </select>
            </div>
            <div>
                <label>Quem emitiu</label>
                <select name="propria">
                    <option value="">Todos</option>
                    <option value="0"<?= $selected($filters['propria'], '0') ?>>Terceiros (recebidas)</option>
                    <option value="1"<?= $selected($filters['propria'], '1') ?>>A própria WCT</option>
                </select>
            </div>
            <div>
                <label>Origem</label>
                <select name="origem">
                    <option value="">Todas</option>
                    <?php foreach (FiscalDocsService::ORIGENS as $key => $label): ?>
                        <option value="<?= $key ?>"<?= $selected($filters['origem'], $key) ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Situação</label>
                <select name="situacao">
                    <option value="">Todas</option>
                    <option value="completo"<?= $selected($filters['situacao'], 'completo') ?>>Com XML completo</option>
                    <option value="resumo"<?= $selected($filters['situacao'], 'resumo') ?>>Só resumo (aguardando XML)</option>
                    <option value="cancelada"<?= $selected($filters['situacao'], 'cancelada') ?>>Canceladas</option>
                </select>
            </div>
            <div style="grid-column: span 2"><label>Busca</label><input type="text" name="busca" value="<?= htmlspecialchars($filters['busca']) ?>" placeholder="Emitente, destinatário, CNPJ, chave, número, natureza"></div>
        </div>
        <div class="fd-actions">
            <button type="submit">Pesquisar</button>
            <?php if ($total > 0): ?>
                <a class="fd-btn" href="<?= htmlspecialchars($zipAllUrl) ?>">Baixar ZIP com todos do filtro (<?= min($total, 5000) ?>)</a>
            <?php endif; ?>
        </div>
    </form>

    <form method="post" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=documentos-fiscais&download=zip')) ?>">
        <p class="fd-hint">
            <?= number_format($total, 0, ',', '.') ?> documento(s) no filtro<?= $total > $listLimit ? ' — mostrando os ' . $listLimit . ' mais recentes' : '' ?>.
        </p>
        <?php if ($docs !== []): ?>
            <div class="fd-actions" style="margin-bottom:6px"><button type="submit">Baixar ZIP dos selecionados</button></div>
            <div class="fd-table-wrap">
                <table class="fd-table">
                    <thead>
                    <tr>
                        <th><input type="checkbox" data-fd-all title="Selecionar todos"></th>
                        <th>Emissão</th>
                        <th>Tipo</th>
                        <th>Nº / Série</th>
                        <th>Emitente</th>
                        <th>Destinatário</th>
                        <th>Valor</th>
                        <th>Natureza</th>
                        <th>Origem</th>
                        <th>Chave</th>
                        <th>XML</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($docs as $d): ?>
                        <?php
                        $tagClass = match ((string) $d['tipo']) {
                            'nfe' => 'nfe', 'nfe_resumo' => 'res', 'cte' => 'cte', default => 'evt',
                        };
                        ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>"></td>
                            <td style="white-space:nowrap"><?= htmlspecialchars($fmtDate($d['dh_emi'])) ?></td>
                            <td>
                                <span class="fd-tag <?= $tagClass ?>"><?= htmlspecialchars($tipoLabel($d)) ?></span>
                                <?php if (($d['situacao'] ?? '') === 'cancelada'): ?><br><span class="fd-tag bad">cancelada</span><?php endif; ?>
                                <?php if ((int) $d['propria'] === 1): ?><br><span class="fd-tag own">emitida pela WCT</span><?php endif; ?>
                                <?php if ($d['tipo'] === 'nfe_resumo'): ?>
                                    <br><small class="fd-hint">
                                        <?= match ((string) ($d['manifest_status'] ?? '')) {
                                            'pendente' => 'Ciência na fila',
                                            'ciencia' => 'Ciência registrada, XML a caminho',
                                            'erro' => 'Ciência recusada: ' . htmlspecialchars((string) ($d['manifest_msg'] ?? '')),
                                            default => 'Sem Ciência',
                                        } ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(trim((string) $d['numero'] . ($d['serie'] !== null && $d['serie'] !== '' ? ' / ' . $d['serie'] : ''))) ?: '—' ?></td>
                            <td>
                                <?= htmlspecialchars((string) ($d['emit_nome'] ?? '')) ?: '—' ?>
                                <?php if (!empty($d['emit_cnpj'])): ?><br><small><?= htmlspecialchars($fmtDoc($d['emit_cnpj'])) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars((string) ($d['dest_nome'] ?? '')) ?: '—' ?>
                                <?php if (!empty($d['dest_cnpj'])): ?><br><small><?= htmlspecialchars($fmtDoc($d['dest_cnpj'])) ?></small><?php endif; ?>
                            </td>
                            <td style="white-space:nowrap"><?= $d['valor'] !== null ? 'R$ ' . number_format((float) $d['valor'], 2, ',', '.') : '—' ?></td>
                            <td><?= htmlspecialchars((string) ($d['nat_op'] ?? '')) ?: '—' ?></td>
                            <td style="white-space:nowrap"><?= htmlspecialchars(FiscalDocsService::ORIGENS[(string) $d['origem']] ?? (string) $d['origem']) ?></td>
                            <td><small style="font-family:monospace"><?= htmlspecialchars((string) ($d['chave'] ?? '')) ?></small></td>
                            <td><a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=documentos-fiscais&download=xml&id=' . (int) $d['id'])) ?>">baixar</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </form>
</section>

<script>
(function () {
    var all = document.querySelector('[data-fd-all]');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) { cb.checked = all.checked; });
        });
    }
    document.querySelectorAll('form[data-fd-loading]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type=submit]');
            if (btn) { setTimeout(function () { btn.disabled = true; btn.textContent = form.getAttribute('data-fd-loading'); }, 0); }
        });
    });
})();
</script>
