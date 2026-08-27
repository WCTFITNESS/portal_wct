<?php

declare(strict_types=1);

/** @var string|null $authFeedback */
/** @var string $authFeedbackClass */
/** @var string $authResetToken */
/** @var bool $authResetDone */

$feedback = $authFeedback ?? null;
$feedbackClass = $authFeedbackClass ?? 'ok';
$token = $authResetToken ?? '';
$done = !empty($authResetDone);

$pageUrl = portal_wct_public_path($baseUrl, 'index.php?page=reset-password');
$loginUrl = portal_wct_public_path($baseUrl, 'index.php?page=login');
?>
<style>
    .auth-wrap {
        min-height: calc(100vh - 40px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px 12px;
    }
    .auth-card {
        width: min(420px, 100%);
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 28px 24px 22px;
        box-shadow: 0 12px 40px rgba(15, 23, 42, .08);
    }
    .auth-card h1 { margin: 0 0 6px; font-size: 1.35rem; }
    .auth-lead { margin: 0 0 18px; color: #64748b; font-size: .92rem; }
    .auth-card label { display: block; margin-top: 12px; font-size: .85rem; font-weight: 600; }
    .auth-card input { width: 100%; box-sizing: border-box; margin-top: 4px; }
    .auth-pass { position: relative; display: flex; align-items: center; }
    .auth-pass input { padding-right: 78px; }
    .auth-pass-toggle {
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
    .auth-actions { margin-top: 18px; display: flex; flex-direction: column; gap: 10px; }
    .auth-actions button { width: 100%; }
    .auth-links { text-align: center; font-size: .88rem; }
    .auth-links a { color: #334155; font-weight: 600; }
    .auth-brand {
        text-align: center;
        margin-bottom: 16px;
        font-weight: 800;
        letter-spacing: .04em;
        font-size: 1.4rem;
    }
    .auth-brand .accent { color: #d50000; }
</style>

<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-brand"><span class="accent">W</span>CT Portal</div>
        <h1>Nova senha</h1>
        <p class="auth-lead">Defina uma nova senha para acessar o portal.</p>

        <?php if ($feedback !== null): ?>
            <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="auth-links"><a href="<?= htmlspecialchars($loginUrl) ?>">Ir para o login</a></div>
        <?php elseif ($token === ''): ?>
            <p class="msg err">Link inválido. Solicite uma nova redefinição de senha.</p>
            <div class="auth-links"><a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=forgot-password')) ?>">Esqueci a senha</a></div>
        <?php else: ?>
            <form method="post" action="<?= htmlspecialchars($pageUrl) ?>">
                <input type="hidden" name="form_type" value="reset">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <label for="reset-password">Nova senha</label>
                <div class="auth-pass">
                    <input id="reset-password" type="password" name="password" required minlength="4" autocomplete="new-password">
                    <button type="button" class="auth-pass-toggle" data-toggle-password="reset-password">Ver</button>
                </div>

                <label for="reset-password-confirm">Confirmar senha</label>
                <div class="auth-pass">
                    <input id="reset-password-confirm" type="password" name="password_confirm" required minlength="4" autocomplete="new-password">
                    <button type="button" class="auth-pass-toggle" data-toggle-password="reset-password-confirm">Ver</button>
                </div>

                <div class="auth-actions">
                    <button type="submit">Salvar senha</button>
                    <div class="auth-links"><a href="<?= htmlspecialchars($loginUrl) ?>">Voltar ao login</a></div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

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
