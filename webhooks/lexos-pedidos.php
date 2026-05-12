<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/app.php';
$service = $app['lexosOrderWebhookService'];
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

header('Content-Type: application/json; charset=utf-8');

if ($method === 'GET') {
    $baseUrl = (string) ($app['config']['app']['base_url'] ?? '/');
    echo json_encode([
        'ok' => true,
        'service' => 'lexos-order-webhook',
        'method' => 'POST',
        'description' => 'Recebe notificações de pedidos da Lexos Hub e grava eventos para o Monitor de Pedidos.',
        'stored_events' => $service->countStoredEvents(),
        'endpoint' => portal_wct_public_path($baseUrl, 'webhooks/lexos-pedidos.php'),
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido. Use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '') {
    $rawBody = json_encode($_POST, JSON_UNESCAPED_UNICODE) ?: '';
}

try {
    $result = $service->ingestPayload($rawBody);
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
