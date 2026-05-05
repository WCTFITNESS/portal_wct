<?php

declare(strict_types=1);

/**
 * Gera config/db-runtime.php usando o mesmo ambiente que o PHP CLI recebe ao subir.
 * Com mod_php, getenv() pode não ver todas as vars do Render; o CLI sempre vê as do container.
 * Chamado pelo docker-entrypoint.sh antes do Apache.
 */

$portalRoot = dirname(__DIR__, 2);
require __DIR__ . '/mysql_env.php';

$db = portal_wct_db_from_environment();
$target = $portalRoot . '/config/db-runtime.php';

// Log which env vars we got (no secrets) to debug Render wiring issues.
$portalUrlRaw = getenv('PORTAL_DATABASE_URL');
$dataUrlRaw = getenv('DATABASE_URL');
$hasPortalUrl = is_string($portalUrlRaw) && trim($portalUrlRaw) !== '';
$hasDataUrl = is_string($dataUrlRaw) && trim($dataUrlRaw) !== '';
fwrite(STDOUT, "[bake-db-runtime] PORTAL_DATABASE_URL=" . ($hasPortalUrl ? 'set' : 'unset') . ", DATABASE_URL=" . ($hasDataUrl ? 'set' : 'unset') . "\n");

fwrite(
    STDOUT,
    "[bake-db-runtime] driver={$db['driver']} host={$db['host']} port={$db['port']} db={$db['name']} user={$db['user']}\n"
);

file_put_contents(
    $target,
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($db, true) . ";\n"
);

chmod($target, 0644);
