<?php
$app = require __DIR__ . '/../app.php';
$c = $app['settingsRepository']->getApiConfig();
echo 'hub_refresh=' . (trim($c['lexos_hub_refresh_token'] ?? '') !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'hub_access=' . (trim($c['lexos_hub_token'] ?? '') !== '' ? 'yes' : 'no') . PHP_EOL;
echo 'oauth_refresh=' . (trim($c['lexos_refresh_token'] ?? '') !== '' ? 'yes' : 'no') . PHP_EOL;
