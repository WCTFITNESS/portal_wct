<?php

declare(strict_types=1);

$config = require __DIR__ . '/config/config.php';

date_default_timezone_set($config['app']['timezone']);

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

    header('Location: ' . $config['app']['base_url'] . $path);
    exit;
}
