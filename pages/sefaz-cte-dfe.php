<?php

declare(strict_types=1);

use App\Services\SefazCteDistribuicaoService;

/** @var SefazCteDistribuicaoService $dfe */
$dfe = $app['sefazCteDistribuicaoService'];
$dfeRepo = $app['sefazDfeRepository'];
$isPortalAdmin = !empty($currentPortalUser['is_admin']);

$feedback = null;
$feedbackClass = 'ok';
if (isset($_GET['flash_err']) && $_GET['flash_err'] !== '') {
    $feedback = (string) $_GET['flash_err'];
    $feedbackClass = 'err';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formType = (string) ($_POST['form_type'] ?? '');
    try {
        if (in_array($formType, ['dfe_config', 'dfe_cert', 'dfe_cert_remove'], true) && !$isPortalAdmin) {
            throw new RuntimeException('Somente administradores do portal podem alterar o certificado e a configuração.');
        }

        if ($formType === 'dfe_config') {
            $dfe->saveConfig((string) ($_POST['cnpj'] ?? ''), (string) ($_POST['uf_autor'] ?? ''));
            $feedback = 'Configuração salva.';
        }

        if ($formType === 'dfe_cert') {
            $upload = $_FILES['pfx'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Selecione o arquivo do certificado (.pfx ou .p12).');
            }
            if ((int) $upload['size'] > 200 * 1024) {
                throw new RuntimeException('Arquivo grande demais para um certificado A1.');
            }
            $info = $dfe->importCertificate(
                (string) file_get_contents((string) $upload['tmp_name']),
                (string) ($_POST['pfx_password'] ?? '')
            );
            @unlink((string) $upload['tmp_name']);
            $feedback = 'Certificado carregado: ' . $info['subject']
                . ($info['valid_to'] !== '' ? ' (válido até ' . $info['valid_to'] . ')' : '') . '.';
        }

        if ($formType === 'dfe_cert_remove') {
            $dfe->removeCertificate();
            $feedback = 'Certificado removido do portal.';
        }

        if ($formType === 'dfe_sync') {
            ignore_user_abort(true);
            @set_time_limit(240);
            $result = $dfe->sync();
            $feedback = $result['mensagem'] . ' Documentos novos: ' . $result['novos'] . '.';
            $feedbackClass = $result['concluido'] || $result['novos'] > 0 ? 'ok' : 'err';
        }
    } catch (Throwable $e) {
        $feedback = $e->getMessage();
        $feedbackClass = 'err';
    }
}

$status = $dfe->getStatus();
$filters = [
    'de' => (string) ($_GET['de'] ?? date('Y-m-d', strtotime('-90 days'))),
    'ate' => (string) ($_GET['ate'] ?? date('Y-m-d')),
    'busca' => trim((string) ($_GET['busca'] ?? '')),
    'tipo' => (string) ($_GET['tipo'] ?? 'cte'),
];
$listLimit = 500;
$total = $dfeRepo->countDocuments($filters);
$docs = $dfeRepo->listDocuments($filters, $listLimit);

$pageUrl = portal_wct_public_path($baseUrl, 'index.php?page=sefaz-cte-dfe');
$zipAllUrl = portal_wct_public_path(
    $baseUrl,
    'index.php?' . http_build_query(['page' => 'sefaz-cte-dfe', 'download' => 'zip', 'todos' => '1'] + $filters)
);
$fmtDate = static fn ($v): string => $v ? date('d/m/Y H:i', strtotime((string) $v)) : '—';
$fmtCnpj = static function ($v): string {
    $d = preg_replace('/\D/', '', (string) $v) ?? '';

    return strlen($d) === 14
        ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2)
        : (string) $v;
};
?>
<style>
    .dfe-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
    .dfe-stats { display: flex; flex-wrap: wrap; gap: 18px; margin: 8px 0 4px; font-size: .92rem; }
    .dfe-stats strong { display: block; font-size: .75rem; color: #64748b; text-transform: uppercase; }
    .dfe-hint { color: #64748b; font-size: .85rem; margin: 6px 0 0; }
    .dfe-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .dfe-actions button, .dfe-actions a.dfe-btn { width: auto; }
    a.dfe-btn { display: inline-block; margin-top: 16px; padding: 10px 16px; border: 1px solid #f5b700; border-radius: 6px; background: #fff; color: #111; font-weight: bold; text-decoration: none; text-transform: uppercase; font-size: .85rem; }
    .dfe-table-wrap { overflow-x: auto; max-height: 70vh; }
    .dfe-table th { position: sticky; top: 0; background: #f1f5f9; white-space: nowrap; font-size: .75rem; text-transform: uppercase; }
    .dfe-table td { font-size: .82rem; vertical-align: top; }
    .dfe-table input[type=checkbox] { width: auto; margin: 0; }
    .dfe-tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .72rem; background: #e2e8f0; }
    .dfe-tag.evt { background: #fef3c7; }
    .dfe-warn { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; padding: 10px 12px; border-radius: 8px; margin: 10px 0; }
</style>

<section class="card">
    <h1>CT-e na SEFAZ (XML)</h1>
    <p class="dfe-hint" style="margin-top:0">
        Baixa direto da SEFAZ (Ambiente Nacional) todos os CT-e em que a WCT aparece — inclusive os da Casas Bahia Entrega / Envvias.
        A SEFAZ entrega apenas documentos dos <strong>últimos 3 meses</strong> e, quando não há nada novo, só libera nova consulta depois de 1 hora.
    </p>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <?php if (!$status['has_cert']): ?>
        <div class="dfe-warn">
            Nenhum certificado carregado. <?= $isPortalAdmin
                ? 'Carregue abaixo o certificado digital A1 (e-CNPJ, arquivo .pfx) da empresa.'
                : 'Peça a um administrador do portal para carregar o certificado A1 da empresa.' ?>
        </div>
    <?php elseif ($status['cert_expired']): ?>
        <div class="dfe-warn">O certificado carregado está vencido. Um administrador precisa carregar o novo.</div>
    <?php endif; ?>

    <div class="dfe-stats">
        <div><strong>Certificado</strong><?= $status['has_cert'] ? htmlspecialchars((string) ($status['cert_subject'] ?? 'carregado')) : '—' ?></div>
        <div><strong>Validade</strong><?= htmlspecialchars($fmtDate($status['cert_valid_to'] ?? null)) ?></div>
        <div><strong>CNPJ consultado</strong><?= htmlspecialchars($fmtCnpj($status['cnpj'])) ?: '—' ?></div>
        <div><strong>Última busca</strong><?= htmlspecialchars($fmtDate($status['last_sync_at'] ?? null)) ?></div>
        <div><strong>NSU</strong><?= htmlspecialchars((string) $status['ult_nsu']) ?> / <?= htmlspecialchars((string) $status['max_nsu']) ?></div>
    </div>
    <?php if (!empty($status['last_status'])): ?>
        <p class="dfe-hint">Último resultado: <?= htmlspecialchars((string) $status['last_status']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" class="dfe-actions" data-dfe-loading="Consultando a SEFAZ... pode levar até 2 minutos.">
        <input type="hidden" name="form_type" value="dfe_sync">
        <button type="submit"<?= (!$status['has_cert'] || !$status['can_sync_now']) ? ' disabled' : '' ?>>Buscar CT-e novos na SEFAZ</button>
        <?php if (!$status['can_sync_now'] && $status['next_sync_ts']): ?>
            <span class="dfe-hint" style="margin-top:16px">Liberado a partir de <?= date('d/m/Y H:i', (int) $status['next_sync_ts']) ?>.</span>
        <?php endif; ?>
    </form>
</section>

<section class="card">
    <h2 style="margin-top:0">Documentos baixados</h2>
    <form method="get" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php')) ?>">
        <input type="hidden" name="page" value="sefaz-cte-dfe">
        <div class="dfe-grid">
            <div><label>Emissão de</label><input type="date" name="de" value="<?= htmlspecialchars($filters['de']) ?>"></div>
            <div><label>Emissão até</label><input type="date" name="ate" value="<?= htmlspecialchars($filters['ate']) ?>"></div>
            <div>
                <label>Tipo</label>
                <select name="tipo">
                    <option value="cte"<?= $filters['tipo'] === 'cte' ? ' selected' : '' ?>>CT-e</option>
                    <option value="evento"<?= $filters['tipo'] === 'evento' ? ' selected' : '' ?>>Eventos (cancelamento, entrega...)</option>
                    <option value=""<?= $filters['tipo'] === '' ? ' selected' : '' ?>>Todos</option>
                </select>
            </div>
            <div><label>Busca</label><input type="text" name="busca" value="<?= htmlspecialchars($filters['busca']) ?>" placeholder="Transportadora, destinatário, chave, nº CT-e"></div>
        </div>
        <div class="dfe-actions">
            <button type="submit">Filtrar</button>
            <?php if ($total > 0): ?>
                <a class="dfe-btn" href="<?= htmlspecialchars($zipAllUrl) ?>">Baixar ZIP com todos do filtro (<?= min($total, 5000) ?>)</a>
            <?php endif; ?>
        </div>
    </form>

    <form method="post" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=sefaz-cte-dfe&download=zip')) ?>">
        <p class="dfe-hint">
            <?= number_format($total, 0, ',', '.') ?> documento(s) no filtro<?= $total > $listLimit ? ' — mostrando os ' . $listLimit . ' mais recentes' : '' ?>.
        </p>
        <?php if ($docs !== []): ?>
            <div class="dfe-actions" style="margin-bottom:6px">
                <button type="submit">Baixar ZIP dos selecionados</button>
            </div>
            <div class="dfe-table-wrap">
                <table class="dfe-table">
                    <thead>
                    <tr>
                        <th><input type="checkbox" data-dfe-all title="Selecionar todos"></th>
                        <th>Emissão</th>
                        <th>Tipo</th>
                        <th>Nº / Série</th>
                        <th>Transportadora</th>
                        <th>Remetente</th>
                        <th>Destinatário</th>
                        <th>Valor</th>
                        <th>Chave</th>
                        <th>XML</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($docs as $d): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= (int) $d['id'] ?>"></td>
                            <td style="white-space:nowrap"><?= htmlspecialchars($fmtDate($d['dh_emi'])) ?></td>
                            <td>
                                <?php if ($d['tipo'] === 'evento'): ?>
                                    <span class="dfe-tag evt"><?= htmlspecialchars((string) ($d['desc_evento'] ?: 'Evento ' . $d['tp_evento'])) ?></span>
                                <?php else: ?>
                                    <span class="dfe-tag"><?= $d['tipo'] === 'cte' ? 'CT-e' : htmlspecialchars((string) $d['schema_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(trim((string) $d['numero'] . ($d['serie'] !== null && $d['serie'] !== '' ? ' / ' . $d['serie'] : ''))) ?: '—' ?></td>
                            <td>
                                <?= htmlspecialchars((string) ($d['emit_nome'] ?? '')) ?: '—' ?>
                                <?php if (!empty($d['emit_cnpj'])): ?><br><small><?= htmlspecialchars($fmtCnpj($d['emit_cnpj'])) ?></small><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($d['rem_nome'] ?? '')) ?: '—' ?></td>
                            <td><?= htmlspecialchars((string) ($d['dest_nome'] ?? '')) ?: '—' ?></td>
                            <td style="white-space:nowrap"><?= $d['valor'] !== null ? 'R$ ' . number_format((float) $d['valor'], 2, ',', '.') : '—' ?></td>
                            <td><small style="font-family:monospace"><?= htmlspecialchars((string) ($d['chave'] ?? '')) ?></small></td>
                            <td>
                                <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=sefaz-cte-dfe&download=xml&id=' . (int) $d['id'])) ?>">baixar</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </form>
</section>

<?php if ($isPortalAdmin): ?>
<section class="card">
    <h2 style="margin-top:0">Certificado e configuração <small style="font-weight:normal;color:#64748b">(somente administradores)</small></h2>
    <p class="dfe-hint" style="margin-top:0">
        Use o certificado <strong>A1 (arquivo .pfx/.p12) do e-CNPJ da empresa</strong>. Certificado A3 (token/cartão) não funciona aqui.
        O arquivo fica guardado criptografado no portal, a senha não é armazenada e ninguém consegue baixar o certificado de volta pela tela.
        <?= $dfe->isKeyFromEnv() ? '' : 'Para reforçar a proteção, defina a variável de ambiente PORTAL_DFE_KEY no servidor antes de carregar o certificado.' ?>
    </p>

    <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="form_type" value="dfe_cert">
        <div class="dfe-grid">
            <div><label>Arquivo do certificado (.pfx / .p12)</label><input type="file" name="pfx" accept=".pfx,.p12" required></div>
            <div><label>Senha do certificado</label><input type="password" name="pfx_password" autocomplete="new-password" required></div>
        </div>
        <div class="dfe-actions">
            <button type="submit"><?= $status['has_cert'] ? 'Substituir certificado' : 'Carregar certificado' ?></button>
        </div>
    </form>

    <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" style="margin-top:14px">
        <input type="hidden" name="form_type" value="dfe_config">
        <div class="dfe-grid">
            <div>
                <label>CNPJ consultado</label>
                <input type="text" name="cnpj" value="<?= htmlspecialchars($fmtCnpj($status['cnpj'] ?: ($status['cert_cnpj'] ?? '17751890000176'))) ?>" required>
                <p class="dfe-hint">Precisa ser o mesmo CNPJ (ou da mesma raiz) do certificado.</p>
            </div>
            <div>
                <label>UF da empresa</label>
                <select name="uf_autor">
                    <?php foreach (SefazCteDistribuicaoService::UF_CODES as $code => $sigla): ?>
                        <option value="<?= $code ?>"<?= (string) $status['uf_autor'] === (string) $code ? ' selected' : '' ?>><?= $sigla ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="dfe-actions">
            <button type="submit">Salvar configuração</button>
        </div>
    </form>

    <?php if ($status['has_cert']): ?>
        <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" onsubmit="return confirm('Remover o certificado do portal? As buscas param até carregar outro.');">
            <input type="hidden" name="form_type" value="dfe_cert_remove">
            <div class="dfe-actions"><button type="submit" style="background:#fff;color:#a12323;border-color:#a12323">Remover certificado</button></div>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>

<script>
(function () {
    var all = document.querySelector('[data-dfe-all]');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('input[name="ids[]"]').forEach(function (cb) { cb.checked = all.checked; });
        });
    }
    document.querySelectorAll('form[data-dfe-loading]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type=submit]');
            if (btn) { btn.disabled = true; btn.textContent = form.getAttribute('data-dfe-loading'); }
        });
    });
})();
</script>
