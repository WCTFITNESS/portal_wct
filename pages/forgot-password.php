<?php

declare(strict_types=1);

/** @var string|null $authFeedback */
/** @var string $authFeedbackClass */
/** @var string $authEmailValue */

$feedback = $authFeedback ?? null;
$feedbackClass = $authFeedbackClass ?? 'ok';
$emailValue = $authEmailValue ?? '';

$pageUrl = portal_wct_public_path($baseUrl, 'index.php?page=forgot-password');
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
        <h1>Esqueci a senha</h1>
        <p class="auth-lead">Informe seu e-mail para receber o link de redefinição.</p>

        <?php if ($feedback !== null): ?>
            <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($pageUrl) ?>">
            <input type="hidden" name="form_type" value="forgot">
            <label for="forgot-email">E-mail</label>
            <input id="forgot-email" type="email" name="email" required value="<?= htmlspecialchars($emailValue) ?>" autocomplete="username">

            <div class="auth-actions">
                <button type="submit">Enviar link</button>
                <div class="auth-links">
                    <a href="<?= htmlspecialchars($loginUrl) ?>">Voltar ao login</a>
                </div>
            </div>
        </form>
    </div>
</div>
