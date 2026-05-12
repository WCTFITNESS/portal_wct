<?php

declare(strict_types=1);

$config = require __DIR__ . '/config/config.php';

date_default_timezone_set($config['app']['timezone']);

/**
 * Junta base_url (ex.: "/" ou "/portal_wct") com um caminho relativo sem gerar "//index.php"
 * (o navegador trata "//..." como URL com host "index.php" -> ERR_NAME_NOT_RESOLVED).
 */
function portal_wct_public_path(string $baseUrl, string $relative): string
{
    $relative = ltrim($relative, '/');
    $root = trim($baseUrl, '/');

    return $root === '' ? '/' . $relative : '/' . $root . '/' . $relative;
}

function portal_wct_public_origin(): string
{
    $fromEnv = getenv('PORTAL_PUBLIC_ORIGIN');
    if (is_string($fromEnv) && $fromEnv !== '') {
        return rtrim($fromEnv, '/');
    }

    $renderUrl = getenv('RENDER_EXTERNAL_URL');
    if (is_string($renderUrl) && $renderUrl !== '') {
        return rtrim($renderUrl, '/');
    }

    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';

    return ($https ? 'https' : 'http') . '://' . $host;
}

function portal_wct_absolute_url(string $baseUrl, string $relative): string
{
    $path = portal_wct_public_path($baseUrl, $relative);
    $origin = portal_wct_public_origin();
    if ($origin === '') {
        return $path;
    }

    return $origin . $path;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/app/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

function redirect_to(string $path): void
{
    global $config;

    header('Location: ' . portal_wct_public_path($config['app']['base_url'], ltrim($path, '/')));
    exit;
}
