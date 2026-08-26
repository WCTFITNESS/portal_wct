<?php

declare(strict_types=1);

use App\Services\PortalAuthService;

/** @var PortalAuthService $auth */
$auth = $app['portalAuthService'];
$currentUser = $auth->currentUser();
$modules = PortalAuthService::modulesCatalog();

$feedback = null;
$feedbackClass = 'ok';
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editUser = $editId > 0 ? $auth->findUser($editId) : null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formType = (string) ($_POST['form_type'] ?? '');
    try {
        if ($formType === 'user_create') {
            $mods = is_array($_POST['modules'] ?? null) ? $_POST['modules'] : [];
            $result = $auth->createUser(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                !empty($_POST['is_admin']),
                !isset($_POST['is_active']) || !empty($_POST['is_active']),
                $mods
            );
            $feedback = $result['message'];
            $feedbackClass = $result['ok'] ? 'ok' : 'err';
        }

        if ($formType === 'user_update') {
            $id = (int) ($_POST['id'] ?? 0);
            $mods = is_array($_POST['modules'] ?? null) ? $_POST['modules'] : [];
            $result = $auth->updateUser(
                $id,
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                !empty($_POST['is_admin']),
                !empty($_POST['is_active']),
                $mods,
                (string) ($_POST['password'] ?? '')
            );
            $feedback = $result['message'];
            $feedbackClass = $result['ok'] ? 'ok' : 'err';
            if ($result['ok']) {
                $editId = 0;
                $editUser = null;
            } else {
                $editId = $id;
                $editUser = $auth->findUser($id);
            }
        }

        if ($formType === 'user_delete') {
            $result = $auth->deleteUser((int) ($_POST['id'] ?? 0), $currentUser);
            $feedback = $result['message'];
            $feedbackClass = $result['ok'] ? 'ok' : 'err';
            $editId = 0;
            $editUser = null;
        }
    } catch (Throwable $e) {
        $feedback = $e->getMessage();
        $feedbackClass = 'err';
    }
}

$users = $auth->listUsers();
$pageUrl = portal_wct_public_path($baseUrl, 'index.php?page=config-usuarios');
?>
<style>
    .cfg-grid {
        display: grid;
        grid-template-columns: minmax(280px, 380px) 1fr;
        gap: 16px;
        align-items: start;
    }
    .cfg-mods {
        display: grid;
        grid-template-columns: 1fr;
        gap: 6px;
        margin-top: 8px;
        max-height: 260px;
        overflow: auto;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px;
        background: #f8fafc;
    }
    .cfg-mods label {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0;
        font-weight: 500;
        font-size: .88rem;
    }
    .cfg-mods input { width: auto; margin: 0; }
    .cfg-pass { position: relative; display: flex; align-items: center; }
    .cfg-pass input { padding-right: 78px; width: 100%; box-sizing: border-box; }
    .cfg-pass-toggle {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: #111;
        color: #f5b700;
        font-size: .72rem;
        font-weight: 700;
        padding: 6px 10px;
        border-radius: 6px;
        cursor: pointer;
        margin: 0;
        width: auto;
    }
    .cfg-table-wrap { overflow: auto; }
    .cfg-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .cfg-table th, .cfg-table td {
        text-align: left;
        padding: 10px 8px;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
    }
    .cfg-table th { font-size: .75rem; text-transform: uppercase; color: #64748b; }
    .cfg-badge {
        display: inline-block;
        font-size: .7rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 999px;
        background: #e2e8f0;
        color: #334155;
        margin: 0 4px 4px 0;
    }
    .cfg-badge.admin { background: #111; color: #f5b700; }
    .cfg-badge.off { background: #fee2e2; color: #991b1b; }
    .cfg-actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .cfg-actions form { display: inline; }
    .cfg-actions button, .cfg-actions a.buttonish {
        width: auto;
        padding: 6px 10px;
        font-size: .8rem;
        text-decoration: none;
        display: inline-block;
    }
    .cfg-hint { font-size: .8rem; color: #64748b; margin: 4px 0 0; }
    @media (max-width: 960px) {
        .cfg-grid { grid-template-columns: 1fr; }
    }
</style>

<section class="card">
    <h1>Configurações — Usuários e acessos</h1>
    <p style="color:#64748b;margin:0 0 14px;">
        Cadastre usuários e defina quais módulos cada um pode acessar.
        Administradores têm acesso a todos os módulos.
    </p>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <div class="cfg-grid">
        <div>
            <h2 style="margin:0 0 10px;font-size:1.05rem;">
                <?= $editUser ? 'Editar usuário' : 'Novo usuário' ?>
            </h2>
            <form method="post" action="<?= htmlspecialchars($pageUrl) ?>">
                <input type="hidden" name="form_type" value="<?= $editUser ? 'user_update' : 'user_create' ?>">
                <?php if ($editUser): ?>
                    <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
                <?php endif; ?>

                <label>Nome</label>
                <input type="text" name="name" required value="<?= htmlspecialchars((string) ($editUser['name'] ?? '')) ?>">

                <label>E-mail</label>
                <input type="email" name="email" required value="<?= htmlspecialchars((string) ($editUser['email'] ?? '')) ?>">

                <label><?= $editUser ? 'Nova senha (opcional)' : 'Senha' ?></label>
                <div class="cfg-pass">
                    <input
                        id="cfg-password"
                        type="password"
                        name="password"
                        <?= $editUser ? '' : 'required' ?>
                        minlength="4"
                        autocomplete="new-password"
                    >
                    <button type="button" class="cfg-pass-toggle" data-toggle-password="cfg-password">Ver</button>
                </div>
                <?php if ($editUser): ?>
                    <p class="cfg-hint">Deixe em branco para manter a senha atual.</p>
                <?php endif; ?>

                <label style="display:flex;align-items:center;gap:8px;margin-top:12px;">
                    <input type="checkbox" name="is_admin" value="1" <?= !empty($editUser['is_admin']) ? 'checked' : '' ?>>
                    Administrador (todos os módulos)
                </label>
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="is_active" value="1" <?= !$editUser || !empty($editUser['is_active']) ? 'checked' : '' ?>>
                    Ativo
                </label>

                <p style="margin:14px 0 0;font-weight:700;font-size:.85rem;">Módulos liberados</p>
                <div class="cfg-mods">
                    <?php
                    $selected = is_array($editUser['modules'] ?? null) ? $editUser['modules'] : [];
                    foreach ($modules as $key => $mod):
                        $checked = in_array($key, $selected, true) || !empty($editUser['is_admin']);
                        ?>
                        <label>
                            <input type="checkbox" name="modules[]" value="<?= htmlspecialchars($key) ?>" <?= $checked ? 'checked' : '' ?>>
                            <?= htmlspecialchars($mod['label']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                    <button type="submit"><?= $editUser ? 'Salvar alterações' : 'Cadastrar usuário' ?></button>
                    <?php if ($editUser): ?>
                        <a class="buttonish" href="<?= htmlspecialchars($pageUrl) ?>" style="background:#e2e8f0;color:#111;padding:10px 14px;border-radius:8px;font-weight:600;">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div>
            <h2 style="margin:0 0 10px;font-size:1.05rem;">Usuários cadastrados</h2>
            <div class="cfg-table-wrap">
                <table class="cfg-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>Acesso</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users === []): ?>
                            <tr><td colspan="4">Nenhum usuário.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars((string) $u['name']) ?>
                                    <?php if (empty($u['is_active'])): ?>
                                        <span class="cfg-badge off">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars((string) $u['email']) ?></td>
                                <td>
                                    <?php if (!empty($u['is_admin'])): ?>
                                        <span class="cfg-badge admin">Admin</span>
                                    <?php else: ?>
                                        <?php foreach (($u['modules'] ?? []) as $modKey): ?>
                                            <span class="cfg-badge"><?= htmlspecialchars($modules[$modKey]['label'] ?? $modKey) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="cfg-actions">
                                        <a class="buttonish" href="<?= htmlspecialchars($pageUrl . '&edit=' . (int) $u['id']) ?>" style="background:#111;color:#f5b700;padding:6px 10px;border-radius:6px;font-weight:700;">Editar</a>
                                        <?php if ((int) $u['id'] !== (int) ($currentUser['id'] ?? 0)): ?>
                                            <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" onsubmit="return confirm('Excluir este usuário?');">
                                                <input type="hidden" name="form_type" value="user_delete">
                                                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                                <button type="submit" style="background:#991b1b;color:#fff;">Excluir</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-toggle-password');
            var input = id ? document.getElementById(id) : null;
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Ocultar' : 'Ver';
        });
    });
})();
</script>
