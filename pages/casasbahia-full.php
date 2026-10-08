<?php

declare(strict_types=1);

use App\Services\RockitInvoiceService;

/** @var RockitInvoiceService $rockit */
$rockit = $app['rockitService'];
$isPortalAdmin = !empty($currentPortalUser['is_admin']);

$feedback = null;
$feedbackClass = 'ok';
if (isset($_GET['flash_err']) && $_GET['flash_err'] !== '') {
    $feedback = (string) $_GET['flash_err'];
    $feedbackClass = 'err';
}
$showDiag = $isPortalAdmin && !empty($_GET['diag']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formType = (string) ($_POST['form_type'] ?? '');
    try {
        if (in_array($formType, ['rockit_login', 'rockit_company', 'rockit_clear', 'rockit_diag_download'], true) && !$isPortalAdmin) {
            throw new RuntimeException('Somente administradores do portal podem alterar o acesso à Rock.IT.');
        }
        if ($formType === 'rockit_login') {
            $result = $rockit->saveCredentials((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            $feedback = 'Login na Rock.IT OK. Empresas liberadas: ' . count($result['companies']) . '.';
        }
        if ($formType === 'rockit_company') {
            $rockit->setCompany((string) ($_POST['id_company'] ?? ''));
            $feedback = 'Empresa selecionada.';
        }
        if ($formType === 'rockit_clear') {
            $rockit->clearCredentials();
            $feedback = 'Login da Rock.IT removido do portal.';
        }
        if ($formType === 'rockit_diag_download') {
            $showDiag = true;
            $n = $rockit->diagnoseDownload((string) ($_POST['id_order'] ?? ''), !empty($_POST['canceled']));
            $feedback = 'Teste de download: ' . $n . ' XML reconhecido(s). Veja a resposta crua abaixo.';
            $feedbackClass = $n > 0 ? 'ok' : 'err';
        }
    } catch (Throwable $e) {
        $feedback = $e->getMessage();
        $feedbackClass = 'err';
        $showDiag = $showDiag || ($isPortalAdmin && $formType !== 'rockit_login');
    }
}

$status = $rockit->getStatus();
$de = (string) ($_GET['de'] ?? date('Y-m-d', strtotime('-7 days')));
$ate = (string) ($_GET['ate'] ?? date('Y-m-d'));
$invoiceStatus = (string) ($_GET['status_nf'] ?? '');
$tipoFiltro = trim((string) ($_GET['tipo'] ?? ''));
$orders = [];
$truncated = false;
$listed = !empty($_GET['listar']) && $status['configured'];
if ($listed) {
    try {
        @set_time_limit(300);
        $result = $rockit->listOrders($de, $ate, $invoiceStatus);
        $orders = array_map([RockitInvoiceService::class, 'summarizeOrder'], $result['orders']);
        $truncated = $result['truncated'];
        if ($orders === []) {
            $showDiag = $showDiag || $isPortalAdmin;
        }
    } catch (Throwable $e) {
        $feedback = $e->getMessage();
        $feedbackClass = 'err';
        $showDiag = $showDiag || $isPortalAdmin;
    }
}
$tipos = array_values(array_unique(array_filter(array_column($orders, 'type'))));
sort($tipos);
if ($tipoFiltro !== '') {
    $orders = array_values(array_filter($orders, static fn (array $o): bool => $o['type'] === $tipoFiltro));
}

$pageUrl = portal_wct_public_path($baseUrl, 'index.php?page=casasbahia-full');
$fmtDate = static fn (string $v): string => $v !== '' && strtotime($v) ? date('d/m/Y H:i', strtotime($v)) : $v;
?>
<style>
    .cbf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
    .cbf-hint { color: #64748b; font-size: .85rem; margin: 6px 0 0; }
    .cbf-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .cbf-actions button { width: auto; }
    .cbf-table-wrap { overflow-x: auto; max-height: 70vh; }
    .cbf-table th { position: sticky; top: 0; background: #f1f5f9; white-space: nowrap; font-size: .75rem; text-transform: uppercase; }
    .cbf-table td { font-size: .82rem; vertical-align: top; }
    .cbf-table input[type=checkbox] { width: auto; margin: 0; }
    .cbf-tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: .72rem; background: #e2e8f0; }
    .cbf-diag pre { background: #0f172a; color: #e2e8f0; padding: 10px; border-radius: 6px; font-size: .75rem; overflow: auto; max-height: 260px; white-space: pre-wrap; word-break: break-all; }
</style>

<section class="card">
    <h1>Notas do Full Casas Bahia (XML)</h1>
    <p class="cbf-hint" style="margin-top:0">
        No Fulfillment da Casas Bahia as notas são emitidas pela própria Casas Bahia no ERP <strong>Rock.IT</strong>.
        Aqui você lista os pedidos do Full e baixa os XML: venda, retorno simbólico, devolução, remessa por conta e ordem,
        remessa para armazenagem e os cancelamentos.
    </p>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <?php if (!$status['configured']): ?>
        <p class="msg err">
            Login da Rock.IT ainda não cadastrado. <?= $isPortalAdmin ? 'Cadastre no fim da página.' : 'Peça a um administrador do portal.' ?>
        </p>
    <?php else: ?>
        <div class="cbf-actions" style="margin-bottom:6px">
            <span>Empresa consultada na Rock.IT: <strong><?= htmlspecialchars($rockit->currentCompanyLabel()) ?></strong></span>
            <?php if ($isPortalAdmin && count($status['companies']) > 1): ?>
                <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" class="cbf-actions" style="margin:0">
                    <input type="hidden" name="form_type" value="rockit_company">
                    <select name="id_company" style="width:auto;margin-top:0">
                        <?php foreach ($status['companies'] as $c): ?>
                            <option value="<?= htmlspecialchars((string) ($c['IDCompany'] ?? '')) ?>"<?= (string) ($c['IDCompany'] ?? '') === $status['id_company'] ? ' selected' : '' ?>>
                                <?= htmlspecialchars((string) ($c['AccountName'] ?? '') . ' — ' . (string) ($c['CompanyCpfCnpj'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" style="margin-top:0">Trocar empresa</button>
                </form>
            <?php endif; ?>
        </div>
        <form method="get" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php')) ?>">
            <input type="hidden" name="page" value="casasbahia-full">
            <input type="hidden" name="listar" value="1">
            <div class="cbf-grid">
                <div><label>Compra de</label><input type="date" name="de" value="<?= htmlspecialchars($de) ?>" required></div>
                <div><label>Compra até</label><input type="date" name="ate" value="<?= htmlspecialchars($ate) ?>" required></div>
                <div>
                    <label>Status da nota</label>
                    <select name="status_nf">
                        <option value=""<?= $invoiceStatus === '' ? ' selected' : '' ?>>Todos</option>
                        <?php foreach (RockitInvoiceService::INVOICE_STATUS as $code => $label): ?>
                            <option value="<?= $code ?>"<?= $invoiceStatus === (string) $code ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($tipos !== [] || $tipoFiltro !== ''): ?>
                    <div>
                        <label>Tipo de pedido</label>
                        <select name="tipo">
                            <option value="">Todos</option>
                            <?php foreach (array_unique(array_merge($tipos, $tipoFiltro !== '' ? [$tipoFiltro] : [])) as $t): ?>
                                <option value="<?= htmlspecialchars($t) ?>"<?= $tipoFiltro === $t ? ' selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <div class="cbf-actions">
                <button type="submit">Listar pedidos do Full</button>
                <?php if ($isPortalAdmin): ?>
                    <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-top:16px">
                        <input type="checkbox" name="diag" value="1" style="width:auto;margin:0"<?= $showDiag ? ' checked' : '' ?>> mostrar resposta da Rock.IT
                    </label>
                <?php endif; ?>
            </div>
            <p class="cbf-hint">O período é pela data da compra. Se não vier nada, tente "Status da nota: Todos" e um período maior.</p>
        </form>

        <?php if ($listed): ?>
            <form method="post" action="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=casasbahia-full&download=xml')) ?>" id="cbf-list">
                <p class="cbf-hint">
                    <?= count($orders) ?> pedido(s)<?= $truncated ? ' — a Rock.IT limita a 3000 por consulta; diminua o período para ver todos' : '' ?>.
                </p>
                <?php if ($orders !== []): ?>
                    <div class="cbf-actions" style="margin-bottom:6px">
                        <button type="submit">Baixar XML dos selecionados</button>
                        <button type="submit" formaction="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=casasbahia-full&download=cancel')) ?>"
                                style="background:#fff;color:#111">Baixar XML de cancelamento</button>
                    </div>
                    <div class="cbf-table-wrap">
                        <table class="cbf-table">
                            <thead>
                            <tr>
                                <th><input type="checkbox" data-cbf-all checked title="Selecionar todos"></th>
                                <th>Data</th><th>Tipo</th><th>Pedido Casas Bahia</th><th>Pedido canal</th><th>ID Rock.IT</th>
                                <th>NF</th><th>Status NF</th><th>Cliente</th><th>Chave</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($orders as $o): ?>
                                <tr>
                                    <td><?php if ($o['id_order'] !== ''): ?><input type="checkbox" name="ids[]" value="<?= htmlspecialchars($o['id_order']) ?>" checked><?php endif; ?></td>
                                    <td style="white-space:nowrap"><?= htmlspecialchars($fmtDate($o['date'])) ?></td>
                                    <td><?= $o['type'] !== '' ? '<span class="cbf-tag">' . htmlspecialchars($o['type']) . '</span>' : '—' ?></td>
                                    <td><?= htmlspecialchars($o['order_from']) ?: '—' ?></td>
                                    <td><?= htmlspecialchars($o['order']) ?: '—' ?></td>
                                    <td><?= htmlspecialchars($o['id_order']) ?: '—' ?></td>
                                    <td><?= htmlspecialchars($o['nfe_number']) ?: '—' ?></td>
                                    <td><?= htmlspecialchars(RockitInvoiceService::INVOICE_STATUS[$o['invoice_status']] ?? $o['invoice_status']) ?: '—' ?></td>
                                    <td><?= htmlspecialchars($o['consumer']) ?: '—' ?></td>
                                    <td><small style="font-family:monospace"><?= htmlspecialchars($o['chave']) ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($isPortalAdmin): ?>
<section class="card">
    <h2 style="margin-top:0">Acesso à Rock.IT <small style="font-weight:normal;color:#64748b">(somente administradores)</small></h2>
    <?php if ($status['configured']): ?>
        <p class="cbf-hint" style="margin-top:0">
            Conectado como <strong><?= htmlspecialchars($status['email']) ?></strong>.
            <?= htmlspecialchars($status['last_status']) ?>
        </p>
        <?php if (count($status['companies']) > 1): ?>
            <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" class="cbf-actions">
                <input type="hidden" name="form_type" value="rockit_company">
                <div style="min-width:280px">
                    <label>Empresa</label>
                    <select name="id_company">
                        <?php foreach ($status['companies'] as $c): ?>
                            <option value="<?= htmlspecialchars((string) ($c['IDCompany'] ?? '')) ?>"<?= (string) ($c['IDCompany'] ?? '') === $status['id_company'] ? ' selected' : '' ?>>
                                <?= htmlspecialchars((string) ($c['AccountName'] ?? '') . ' — ' . (string) ($c['CompanyCpfCnpj'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Usar esta empresa</button>
            </form>
        <?php elseif (count($status['companies']) === 1): ?>
            <p class="cbf-hint">Empresa: <?= htmlspecialchars((string) ($status['companies'][0]['AccountName'] ?? '') . ' — ' . (string) ($status['companies'][0]['CompanyCpfCnpj'] ?? '')) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" autocomplete="off" style="margin-top:10px">
        <input type="hidden" name="form_type" value="rockit_login">
        <div class="cbf-grid">
            <div><label>E-mail da Rock.IT</label><input type="email" name="email" value="<?= htmlspecialchars($status['email']) ?>" required></div>
            <div><label>Senha da Rock.IT</label><input type="password" name="password" autocomplete="new-password" required></div>
        </div>
        <p class="cbf-hint">A senha é testada na hora e guardada criptografada; ninguém consegue vê-la de volta pela tela.</p>
        <div class="cbf-actions">
            <button type="submit"><?= $status['configured'] ? 'Atualizar login' : 'Salvar e testar login' ?></button>
        </div>
    </form>

    <?php if ($status['configured']): ?>
        <details style="margin-top:14px" <?= $showDiag ? 'open' : '' ?>>
            <summary style="cursor:pointer;color:#2563eb">Diagnóstico da integração</summary>
            <form method="post" action="<?= htmlspecialchars($pageUrl . '&diag=1') ?>" class="cbf-actions">
                <input type="hidden" name="form_type" value="rockit_diag_download">
                <div style="min-width:220px"><label>ID Rock.IT de um pedido</label><input type="text" name="id_order" required></div>
                <label style="display:flex;align-items:center;gap:6px;font-weight:normal;margin-top:30px"><input type="checkbox" name="canceled" value="1" style="width:auto;margin:0"> cancelamento</label>
                <button type="submit">Testar download</button>
            </form>
            <p class="cbf-hint">Mostra a resposta crua da Rock.IT (sem baixar arquivo), para ajustar a integração se algo não vier certo.</p>
            <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" onsubmit="return confirm('Remover o login da Rock.IT do portal?');">
                <input type="hidden" name="form_type" value="rockit_clear">
                <button type="submit" style="width:auto;background:#fff;color:#a12323;border-color:#a12323">Remover login</button>
            </form>
        </details>
    <?php endif; ?>

    <?php if ($showDiag && $rockit->diagnostics() !== []): ?>
        <div class="cbf-diag">
            <?php foreach ($rockit->diagnostics() as $d): ?>
                <p class="cbf-hint"><strong><?= htmlspecialchars($d['label']) ?></strong> — HTTP <?= (int) $d['status'] ?> — <?= htmlspecialchars($d['type']) ?></p>
                <pre><?= htmlspecialchars($d['body']) ?></pre>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<script>
(function () {
    var all = document.querySelector('[data-cbf-all]');
    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('#cbf-list input[name="ids[]"]').forEach(function (cb) { cb.checked = all.checked; });
        });
    }
})();
</script>
