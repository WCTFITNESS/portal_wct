<?php

declare(strict_types=1);

$app = require __DIR__ . '/../app.php';
$c = $app['settingsRepository']->getApiConfig() ?? [];
$diag = $app['lexosHubSessionService']->diagnoseHubSession();

echo 'hub_refresh=' . (trim($c['lexos_hub_refresh_token'] ?? '') !== '' || trim((string) getenv('LEXOS_HUB_REFRESH_TOKEN')) !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'hub_access=' . (trim($c['lexos_hub_token'] ?? '') !== '' || trim((string) getenv('LEXOS_HUB_ACCESS_TOKEN')) !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'oauth_refresh=' . (trim($c['lexos_refresh_token'] ?? '') !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'session_ok=' . (($diag['session_ok'] ?? false) ? 'yes' : 'no') . PHP_EOL;
echo 'probe_ok=' . (($diag['probe_ok'] ?? false) ? 'yes' : 'no') . PHP_EOL;
echo 'message=' . (string) ($diag['message'] ?? '') . PHP_EOL;
