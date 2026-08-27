<?php

declare(strict_types=1);

/** @var string|null $authFeedback */
/** @var string $authFeedbackClass */
/** @var string $authEmailValue */

$feedback = $authFeedback ?? null;
$feedbackClass = $authFeedbackClass ?? 'ok';
$emailValue = $authEmailValue ?? '';

$pageUrl = portal_wct_public_path($baseUrl, 'index.php?page=login');
$forgotUrl = portal_wct_public_path($baseUrl, 'index.php?page=forgot-password');
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
    .auth-card h1 {
        margin: 0 0 6px;
        font-size: 1.35rem;
    }
    .auth-lead {
        margin: 0 0 18px;
        color: #64748b;
        font-size: .92rem;
    }
    .auth-card label { display: block; margin-top: 12px; font-size: .85rem; font-weight: 600; }
    .auth-card input[type="email"],
    .auth-card input[type="password"],
    .auth-card input[type="text"] {
        width: 100%;
        box-sizing: border-box;
        margin-top: 4px;
    }
    .auth-pass {
        position: relative;
        display: flex;
        align-items: center;
    }
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
        <h1>Entrar</h1>
        <p class="auth-lead">Acesse com seu e-mail e senha para usar o portal.</p>

        <?php if ($feedback !== null): ?>
            <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($pageUrl) ?>" autocomplete="on">
            <input type="hidden" name="form_type" value="login">
            <label for="login-email">E-mail</label>
            <input id="login-email" type="email" name="email" required value="<?= htmlspecialchars($emailValue) ?>" autocomplete="username">

            <label for="login-password">Senha</label>
            <div class="auth-pass">
                <input id="login-password" type="password" name="password" required autocomplete="current-password">
                <button type="button" class="auth-pass-toggle" data-toggle-password="login-password" aria-label="Mostrar senha">Ver</button>
            </div>

            <div class="auth-actions">
                <button type="submit">Entrar</button>
                <div class="auth-links">
                    <a href="<?= htmlspecialchars($forgotUrl) ?>">Esqueci a senha</a>
                </div>
            </div>
        </form>
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
            btn.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
        });
    });
})();
</script>
