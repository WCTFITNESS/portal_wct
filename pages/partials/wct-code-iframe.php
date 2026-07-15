<?php

declare(strict_types=1);

$wctCodeRoute = $wctCodeRoute ?? 'dashboard';
$wctCodeTitle = $wctCodeTitle ?? 'WCT CODE';
$wctCodeAppBase = trim((string) ($app['config']['app']['wct_code_url'] ?? ''));
if ($wctCodeAppBase === '') {
    $wctCodeAppBase = portal_wct_public_path($baseUrl, 'wct-code-app');
}
$iframeSrc = rtrim($wctCodeAppBase, '/') . '/' . ltrim((string) $wctCodeRoute, '/');
?>
<section class="wct-code-shell">
    <iframe
        class="wct-code-shell__frame"
        src="<?= htmlspecialchars($iframeSrc, ENT_QUOTES, 'UTF-8') ?>"
        title="<?= htmlspecialchars((string) $wctCodeTitle, ENT_QUOTES, 'UTF-8') ?>"
        loading="lazy"
        referrerpolicy="same-origin"
    ></iframe>
</section>
