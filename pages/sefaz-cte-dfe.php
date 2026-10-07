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

$readUpload = static function (): string {
    $upload = $_FILES['pfx'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Selecione o arquivo do certificado (.pfx ou .p12).');
    }
    if ((int) $upload['size'] > 200 * 1024) {
        throw new RuntimeException('Arquivo grande demais para um certificado A1.');
    }
    $content = (string) file_get_contents((string) $upload['tmp_name']);
    @unlink((string) $upload['tmp_name']);

    return $content;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formType = (string) ($_POST['form_type'] ?? '');
    $profileId = (int) ($_POST['profile_id'] ?? 0);
    try {
        $adminForms = ['dfe_cert_new', 'dfe_cert_replace', 'dfe_config', 'dfe_cert_remove', 'dfe_profile_delete'];
        if (in_array($formType, $adminForms, true) && !$isPortalAdmin) {
            throw new RuntimeException('Somente administradores do portal podem alterar certificados e empresas.');
        }

        if ($formType === 'dfe_cert_new') {
            $info = $dfe->importCertificate(
                null,
                $readUpload(),
                (string) ($_POST['pfx_password'] ?? ''),
                (string) ($_POST['cnpj'] ?? ''),
                (string) ($_POST['uf_autor'] ?? '42'),
                (string) ($_POST['apelido'] ?? '')
            );
            $feedback = 'Certificado carregado: ' . $info['subject']
                . ($info['valid_to'] !== '' ? ' (válido até ' . $info['valid_to'] . ')' : '') . '.';
        }

        if ($formType === 'dfe_cert_replace') {
            $info = $dfe->importCertificate($profileId, $readUpload(), (string) ($_POST['pfx_password'] ?? ''));
            $feedback = 'Certificado substituído: ' . $info['subject']
                . ($info['valid_to'] !== '' ? ' (válido até ' . $info['valid_to'] . ')' : '') . '.';
        }

        if ($formType === 'dfe_config') {
            $dfe->saveConfig(
                $profileId,
                (string) ($_POST['cnpj'] ?? ''),
                (string) ($_POST['uf_autor'] ?? ''),
                (string) ($_POST['apelido'] ?? '')
            );
            $feedback = 'Empresa atualizada.';
        }

        if ($formType === 'dfe_cert_remove') {
            $dfe->removeCertificate($profileId);
            $feedback = 'Certificado removido. Os documentos já baixados continuam disponíveis.';
        }

        if ($formType === 'dfe_profile_delete') {
            $dfe->deleteProfile($profileId);
            $feedback = 'Empresa e documentos dela excluídos do portal.';
        }

        if ($formType === 'dfe_sync' || $formType === 'dfe_sync_all') {
            ignore_user_abort(true);
            @set_time_limit(600);
            if ($formType === 'dfe_sync') {
                $result = $dfe->sync($profileId);
                $feedback = $result['mensagem'] . ' Documentos novos: ' . $result['novos'] . '.';
                $feedbackClass = $result['concluido'] || $result['novos'] > 0 ? 'ok' : 'err';
            } else {
                $parts = array_map(
                    static fn (array $r): string => $r['empresa'] . ': ' . $r['mensagem'] . ' (' . $r['novos'] . ' novos)',
                    $dfe->syncAll()
                );
                $feedback = $parts === [] ? 'Nenhuma empresa com certificado válido.' : implode(' | ', $parts);
            }
        }
    } catch (Throwable $e) {
        $feedback = $e->getMessage();
        $feedbackClass = 'err';
    }
}

$profiles = $dfe->listProfilesStatus();
$profileLabels = [];
foreach ($profiles as $p) {
    $profileLabels[(int) $p['id']] = (string) $p['label'];
}
$filters = [
    'empresa' => (string) ($_GET['empresa'] ?? ''),
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
$ufOptions = static function (string $selected): string {
    $html = '';
    foreach (SefazCteDistribuicaoService::UF_CODES as $code => $sigla) {
        $html .= '<option value="' . $code . '"' . ($selected === (string) $code ? ' selected' : '') . '>' . $sigla . '</option>';
    }

    return $html;
};
$anySyncable = false;
foreach ($profiles as $p) {
    if ($p['has_cert'] && !$p['cert_expired'] && $p['can_sync_now']) {
        $anySyncable = true;
    }
}
?>
<style>
    .dfe-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
    .dfe-hint { color: #64748b; font-size: .85rem; margin: 6px 0 0; }
    .dfe-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .dfe-actions button, .dfe-actions a.dfe-btn { width: auto; }
    a.dfe-btn { display: inline-block; margin-top: 16px; padding: 10px 16px; border: 1px solid #f5b700; border-radius: 6px; background: #fff; color: #111; font-weight: bold; text-decoration: none; text-transform: uppercase; font-size: .85rem; }
    .dfe-table-wrap { overflow-x: auto; max-height: 70vh; }
    .dfe-table th { position: sticky; top: 0; background: #f1f5f9; white-space: nowrap; font-size: .75rem; text-transform: uppercase; }
    .dfe-table td { font-size: .82rem; vertical-align: top; }
    .dfe-table input[type=checkbox] { width: auto; margin: 0; }
    .dfe-table td button { margin-top: 0; padding: 6px 10px; font-size: .72rem; width: auto; }
    .dfe-tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .72rem; background: #e2e8f0; }
    .dfe-tag.evt { background: #fef3c7; }
    .dfe-tag.bad { background: #fee2e2; color: #991b1b; }
    .dfe-warn { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; padding: 10px 12px; border-radius: 8px; margin: 10px 0; }
    .dfe-admin-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-top: 14px; }
    .dfe-admin-card h3 { margin: 0 0 4px; font-size: 1rem; }
    .dfe-admin-card details { margin-top: 8px; }
    .dfe-admin-card summary { cursor: pointer; color: #2563eb; font-size: .85rem; }
</style>

<section class="card">
    <h1>CT-e na SEFAZ (XML)</h1>
    <p class="dfe-hint" style="margin-top:0">
        Baixa direto da SEFAZ (Ambiente Nacional) todos os CT-e em que cada empresa cadastrada aparece — inclusive os da Casas Bahia Entrega / Envvias.
        A SEFAZ entrega apenas documentos dos <strong>últimos 3 meses</strong> e, quando não há nada novo, só libera nova consulta depois de 1 hora.
    </p>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <?php if ($profiles === []): ?>
        <div class="dfe-warn">
            Nenhum certificado carregado. <?= $isPortalAdmin
                ? 'Carregue abaixo o certificado digital A1 (e-CNPJ, arquivo .pfx) de cada empresa.'
                : 'Peça a um administrador do portal para carregar os certificados A1.' ?>
        </div>
    <?php else: ?>
        <div class="dfe-table-wrap" style="max-height:none">
            <table class="dfe-table">
                <thead>
                <tr><th>Empresa</th><th>CNPJ</th><th>Certificado</th><th>Validade</th><th>Última busca</th><th>NSU</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($profiles as $p): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string) $p['label']) ?></strong>
                            <?php if (!empty($p['last_status'])): ?><br><small class="dfe-hint"><?= htmlspecialchars((string) $p['last_status']) ?></small><?php endif; ?>
                        </td>
                        <td style="white-space:nowrap"><?= htmlspecialchars($fmtCnpj($p['cnpj'])) ?></td>
                        <td>
                            <?php if (!$p['has_cert']): ?><span class="dfe-tag bad">sem certificado</span>
                            <?php else: ?><?= htmlspecialchars((string) ($p['cert_subject'] ?? '')) ?><?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <?= htmlspecialchars($fmtDate($p['cert_valid_to'] ?? null)) ?>
                            <?php if ($p['cert_expired']): ?><br><span class="dfe-tag bad">vencido</span><?php endif; ?>
                        </td>
                        <td style="white-space:nowrap"><?= htmlspecialchars($fmtDate($p['last_sync_at'] ?? null)) ?></td>
                        <td style="white-space:nowrap"><?= htmlspecialchars((string) $p['ult_nsu']) ?> / <?= htmlspecialchars((string) $p['max_nsu']) ?></td>
                        <td style="white-space:nowrap">
                            <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" data-dfe-loading="Consultando...">
                                <input type="hidden" name="form_type" value="dfe_sync">
                                <input type="hidden" name="profile_id" value="<?= (int) $p['id'] ?>">
                                <button type="submit"<?= (!$p['has_cert'] || $p['cert_expired'] || !$p['can_sync_now']) ? ' disabled' : '' ?>>Buscar</button>
                            </form>
                            <?php if (!$p['can_sync_now'] && $p['next_sync_ts']): ?>
                                <small class="dfe-hint">a partir de <?= date('d/m H:i', (int) $p['next_sync_ts']) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($profiles) > 1): ?>
            <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" class="dfe-actions" data-dfe-loading="Consultando a SEFAZ para todas as empresas... pode levar alguns minutos.">
                <input type="hidden" name="form_type" value="dfe_sync_all">
                <button type="submit"<?= $anySyncable ? '' : ' disabled' ?>>Buscar CT-e novos de todas as empresas</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card">
    <h2 style="margin-top:0">Documentos baixados</h2>
    <form method="get" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php')) ?>">
        <input type="hidden" name="page" value="sefaz-cte-dfe">
        <div class="dfe-grid">
            <?php if (count($profiles) > 1): ?>
                <div>
                    <label>Empresa</label>
                    <select name="empresa">
                        <option value="">Todas</option>
                        <?php foreach ($profileLabels as $pid => $plabel): ?>
                            <option value="<?= $pid ?>"<?= $filters['empresa'] === (string) $pid ? ' selected' : '' ?>><?= htmlspecialchars($plabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
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
                        <?php if (count($profiles) > 1): ?><th>Empresa</th><?php endif; ?>
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
                            <?php if (count($profiles) > 1): ?><td><?= htmlspecialchars($profileLabels[(int) $d['settings_id']] ?? '—') ?></td><?php endif; ?>
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
    <h2 style="margin-top:0">Certificados e empresas <small style="font-weight:normal;color:#64748b">(somente administradores)</small></h2>
    <p class="dfe-hint" style="margin-top:0">
        Use o certificado <strong>A1 (arquivo .pfx/.p12) do e-CNPJ</strong> de cada empresa. Certificado A3 (token/cartão) não funciona aqui.
        Os arquivos ficam guardados criptografados no portal, a senha não é armazenada e ninguém consegue baixar o certificado de volta pela tela.
        <?= $dfe->isKeyFromEnv() ? '' : 'Para reforçar a proteção, defina a variável de ambiente PORTAL_DFE_KEY no servidor antes de carregar os certificados.' ?>
    </p>

    <div class="dfe-admin-card">
        <h3>Adicionar certificado</h3>
        <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="form_type" value="dfe_cert_new">
            <div class="dfe-grid">
                <div><label>Arquivo do certificado (.pfx / .p12)</label><input type="file" name="pfx" accept=".pfx,.p12" required></div>
                <div><label>Senha do certificado</label><input type="password" name="pfx_password" autocomplete="new-password" required></div>
                <div><label>Nome para identificar</label><input type="text" name="apelido" placeholder="Ex.: WCT Matriz SC" maxlength="100"></div>
                <div>
                    <label>CNPJ consultado (opcional)</label>
                    <input type="text" name="cnpj" placeholder="Em branco = CNPJ do certificado">
                </div>
                <div><label>UF da empresa</label><select name="uf_autor"><?= $ufOptions('42') ?></select></div>
            </div>
            <p class="dfe-hint">Para uma filial, use o certificado da matriz e informe o CNPJ da filial (precisa ter a mesma raiz). Se o CNPJ já estiver cadastrado, o certificado dele é substituído.</p>
            <div class="dfe-actions"><button type="submit">Carregar certificado</button></div>
        </form>
    </div>

    <?php foreach ($profiles as $p): ?>
        <div class="dfe-admin-card">
            <h3><?= htmlspecialchars((string) $p['label']) ?> <small style="font-weight:normal;color:#64748b"><?= htmlspecialchars($fmtCnpj($p['cnpj'])) ?></small></h3>
            <details>
                <summary>Substituir certificado</summary>
                <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="form_type" value="dfe_cert_replace">
                    <input type="hidden" name="profile_id" value="<?= (int) $p['id'] ?>">
                    <div class="dfe-grid">
                        <div><label>Arquivo (.pfx / .p12)</label><input type="file" name="pfx" accept=".pfx,.p12" required></div>
                        <div><label>Senha</label><input type="password" name="pfx_password" autocomplete="new-password" required></div>
                    </div>
                    <div class="dfe-actions"><button type="submit">Substituir</button></div>
                </form>
            </details>
            <details>
                <summary>Editar nome, CNPJ e UF</summary>
                <form method="post" action="<?= htmlspecialchars($pageUrl) ?>">
                    <input type="hidden" name="form_type" value="dfe_config">
                    <input type="hidden" name="profile_id" value="<?= (int) $p['id'] ?>">
                    <div class="dfe-grid">
                        <div><label>Nome</label><input type="text" name="apelido" value="<?= htmlspecialchars((string) ($p['apelido'] ?? '')) ?>" maxlength="100"></div>
                        <div><label>CNPJ consultado</label><input type="text" name="cnpj" value="<?= htmlspecialchars($fmtCnpj($p['cnpj'])) ?>" required></div>
                        <div><label>UF</label><select name="uf_autor"><?= $ufOptions((string) $p['uf_autor']) ?></select></div>
                    </div>
                    <div class="dfe-actions"><button type="submit">Salvar</button></div>
                </form>
            </details>
            <div class="dfe-actions">
                <?php if ($p['has_cert']): ?>
                    <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" onsubmit="return confirm('Remover o certificado desta empresa? Os documentos já baixados continuam.');">
                        <input type="hidden" name="form_type" value="dfe_cert_remove">
                        <input type="hidden" name="profile_id" value="<?= (int) $p['id'] ?>">
                        <button type="submit" style="background:#fff;color:#a12323;border-color:#a12323">Remover certificado</button>
                    </form>
                <?php endif; ?>
                <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" onsubmit="return confirm('Excluir esta empresa E todos os documentos baixados dela?');">
                    <input type="hidden" name="form_type" value="dfe_profile_delete">
                    <input type="hidden" name="profile_id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" style="background:#fff;color:#a12323;border-color:#a12323">Excluir empresa</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
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
            if (btn) { setTimeout(function () { btn.disabled = true; btn.textContent = form.getAttribute('data-dfe-loading'); }, 0); }
        });
    });
})();
</script>
