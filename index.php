<?php

declare(strict_types=1);

use App\Services\LexosHubBrowserCacheService;

$app = require __DIR__ . '/app.php';
$baseUrl = $app['config']['app']['base_url'];
$trackingWctUrl = $app['config']['app']['tracking_wct_url'] ?? 'http://localhost:3001/admin/dashboard';
$page = $_GET['page'] ?? 'dashboard';

if (isset($_GET['wct_code_internal']) && $_GET['wct_code_internal'] === 'token') {
    header('Content-Type: application/json; charset=utf-8');
    $secret = getenv('WCT_CODE_INTERNAL_SECRET') ?: 'wct-internal';
    $provided = (string) ($_SERVER['HTTP_X_WCT_INTERNAL'] ?? '');
    if ($provided === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $apiConfig = $app['settingsRepository']->getApiConfig();
        echo json_encode([
            'access_token' => $app['tokenService']->getValidAccessToken(),
            'seller_id' => (string) ($apiConfig['seller_id'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$mlModulePages = [
    'ml-campanhas',
    'ml-campanhas-pendentes',
    'ml-campanhas-ativas',
    'ml-anuncios-inativos',
    'ml-ads-report',
];
$isMlModulePage = in_array($page, $mlModulePages, true);
$wctCodeModulePages = [
    'wct-code-dashboard',
    'wct-code-campanhas',
    'wct-code-campanhas-pendentes',
    'wct-code-campanhas-ativas',
    'wct-code-inactive-ads',
    'wct-code-anuncios',
    'wct-code-images',
    'wct-code-frete',
];
$isWctCodeModulePage = in_array($page, $wctCodeModulePages, true);

$mlCampanhasPagesEarly = ['ml-campanhas', 'ml-campanhas-pendentes', 'ml-campanhas-ativas'];
if (in_array($page, $mlCampanhasPagesEarly, true) && ($_GET['ml_campanhas_action'] ?? '') === 'upload_demo') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    try {
        $filePath = $app['mlPromotionsService']->generateCampaignUploadDemo();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="campanha_upload_demo.xlsx"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao gerar planilha demo: ' . $exception->getMessage();
    }
    exit;
}

if (in_array($page, $mlCampanhasPagesEarly, true) && ($_GET['ml_campanhas_action'] ?? '') === 'export') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Metodo nao permitido.';
        exit;
    }

    $rawInput = (string) file_get_contents('php://input');
    $body = json_decode($rawInput, true);
    if (!is_array($body)) {
        $body = json_decode(trim((string) ($_POST['export_payload'] ?? '')), true);
    }
    if (!is_array($body)) {
        $body = [];
    }

    $selected = is_array($body['selected'] ?? null) ? $body['selected'] : [];
    $itemStatus = (string) ($body['item_status'] ?? match ($page) {
        'ml-campanhas-pendentes' => 'pending',
        'ml-campanhas-ativas' => 'started',
        default => 'candidate',
    });

    try {
        ignore_user_abort(true);
        @set_time_limit(600);
        $filePath = $app['mlPromotionsService']->exportCampaignAnalytics($selected, $itemStatus);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="campanha.xlsx"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao exportar campanhas: ' . $exception->getMessage();
    }
    exit;
}

if ($page === 'repasse-mp-sync-config') {
    header('Content-Type: application/json; charset=utf-8');
    $expected = getenv('REPASSE_MP_DESKTOP_KEY') ?: '';
    $provided = trim((string) ($_GET['key'] ?? ''));
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'Chave invalida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $token = $app['mercadopagoSettingsRepository']->getAccessToken();
        if ($token === '') {
            throw new \RuntimeException('Token MP nao configurado no portal.');
        }
        echo json_encode([
            'access_token' => $token,
            'MP_BASE' => 'https://api.mercadopago.com',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        http_response_code(503);
        echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (in_array($page, ['dashboard', 'ml-dashboard'], true) && ($_GET['lexos_metabase_api'] ?? '') === 'metrics') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $start = trim((string) ($_GET['lexos_start'] ?? ''));
        $end = trim((string) ($_GET['lexos_end'] ?? ''));
        if ($start === '' || $end === '') {
            $today = new DateTimeImmutable('now');
            $start = $today->modify('first day of this month')->format('Y-m-d');
            $end = $today->format('Y-m-d');
        }

        $metrics = $app['lexosDashboardService']->getDashboardMetrics($start, $end);
        echo json_encode([
            'ok' => true,
            'metrics' => $metrics,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(502);
        echo json_encode([
            'ok' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (in_array($page, ['dashboard', 'ml-dashboard'], true) && ($_GET['lexos_hub_api'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $hubApi = (string) $_GET['lexos_hub_api'];
    $rawInput = (string) file_get_contents('php://input');
    $body = json_decode($rawInput, true);
    if (!is_array($body)) {
        $body = [];
    }

    try {
        if ($body !== []) {
            $hubContext = $app['lexosHubBrowserCacheService']->parseHubContextFromRequest($body);
            $app['lexosHubBrowserCacheService']->saveFromBrowser(
                trim((string) ($body['lexos_hub_token'] ?? '')),
                trim((string) ($body['lexos_hub_refresh_token'] ?? '')),
                $hubContext,
            );
        }

        if ($hubApi === 'products') {
            $start = trim((string) ($body['lexos_start'] ?? $_GET['lexos_start'] ?? ''));
            $end = trim((string) ($body['lexos_end'] ?? $_GET['lexos_end'] ?? ''));
            $search = trim((string) ($body['lexos_search'] ?? $_GET['lexos_search'] ?? ''));
            $take = max(1, min(500, (int) ($body['lexos_products_take'] ?? $_GET['lexos_products_take'] ?? 20)));
            $pageNo = max(1, (int) ($body['lexos_products_page'] ?? $_GET['lexos_products_page'] ?? 1));
            if ($start === '' || $end === '') {
                $today = new DateTimeImmutable('now');
                $start = $today->modify('first day of this month')->format('Y-m-d');
                $end = $today->format('Y-m-d');
            }

            $app['lexosHubSessionService']->maintainHubSessionSilently();
            if ($app['lexosCredentialsService']->getHubAccessToken() === '') {
                echo json_encode([
                    'ok' => false,
                    'code' => 'hub_not_configured',
                    'message' => 'Token Hub ausente no servidor. Use Conectar Lexos Hub.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $productsResp = $app['lexosDashboardService']->getProducts($start, $end, $search, $take, ($pageNo - 1) * $take);
            echo json_encode([
                'ok' => true,
                'items' => $productsResp['items'] ?? [],
                'count' => (int) ($productsResp['count'] ?? 0),
                'page' => $pageNo,
                'take' => $take,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('Ação Hub desconhecida.');
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['page'] ?? '') === 'lexos-hub-connect') {
    $hubAction = (string) ($_GET['action'] ?? '');

    if ($hubAction === 'capture' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $access = trim((string) ($_POST['lexos_hub_token'] ?? ''));
        $refresh = trim((string) ($_POST['lexos_hub_refresh_token'] ?? ''));
        $hubContext = $app['lexosHubBrowserCacheService']->parseHubContextFromRequest($_POST);
        $result = $app['lexosHubBrowserCacheService']->saveFromBrowser($access, $refresh, $hubContext);
        $bootstrap = $app['lexosHubBrowserCacheService']->buildLocalStorageBootstrapScript(
            $app['lexosCredentialsService']->getHubAccessToken() !== '' ? $app['lexosCredentialsService']->getHubAccessToken() : $access,
            $app['lexosCredentialsService']->getHubRefreshToken() !== '' ? $app['lexosCredentialsService']->getHubRefreshToken() : $refresh,
            $hubContext,
        );
        $redirectOk = portal_wct_public_path($baseUrl, 'index.php?page=lexos-hub-connect&captured=1');
        $redirectErr = portal_wct_public_path($baseUrl, 'index.php?page=lexos-hub-connect&error=' . rawurlencode($result['message']));

        header('Content-Type: text/html; charset=utf-8');
        $lsAccess = LexosHubBrowserCacheService::LS_ACCESS;
        $lsRefresh = LexosHubBrowserCacheService::LS_REFRESH;
        $lsContext = LexosHubBrowserCacheService::LS_CONTEXT;
        $lsSynced = LexosHubBrowserCacheService::LS_SYNCED_AT;
        $jsAccess = json_encode($bootstrap['access'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $jsRefresh = json_encode($bootstrap['refresh'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $jsContext = json_encode($bootstrap['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $jsRedirect = json_encode($result['ok'] ? $redirectOk : $redirectErr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Lexos Hub</title></head><body>';
        echo '<p>Salvando tokens do Hub…</p><script>';
        echo 'try{';
        echo 'if(' . $jsAccess . ')localStorage.setItem(' . json_encode($lsAccess) . ',' . $jsAccess . ');';
        echo 'if(' . $jsRefresh . ')localStorage.setItem(' . json_encode($lsRefresh) . ',' . $jsRefresh . ');';
        echo 'if(' . $jsContext . ')localStorage.setItem(' . json_encode($lsContext) . ',JSON.stringify(' . $jsContext . '));';
        echo 'localStorage.setItem(' . json_encode($lsSynced) . ',String(Date.now()));';
        echo 'location.replace(' . $jsRedirect . ');';
        echo '}catch(e){document.body.innerHTML="Erro ao gravar cache: "+e.message;}</script></body></html>';
        exit;
    }

    if ($hubAction === 'status') {
        header('Content-Type: application/json; charset=utf-8');
        $diagnostic = $app['lexosHubSessionService']->diagnoseHubSession();
        $hubStatus = $app['lexosCredentialsService']->getHubStatusSummary();
        echo json_encode([
            'ok' => (bool) ($diagnostic['session_ok'] ?? false),
            'has_access' => (bool) ($diagnostic['has_access'] ?? false),
            'has_refresh' => (bool) ($diagnostic['has_refresh'] ?? false),
            'access_expired' => (bool) ($diagnostic['access_expired'] ?? false),
            'probe_ok' => (bool) ($diagnostic['probe_ok'] ?? false),
            'hub_token_preview' => (string) ($hubStatus['hub_token_preview'] ?? ''),
            'message' => (string) ($diagnostic['message'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($hubAction === 'sync' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }
        try {
            $hubContext = $app['lexosHubBrowserCacheService']->parseHubContextFromRequest($body);
            $result = $app['lexosHubBrowserCacheService']->saveFromBrowser(
                trim((string) ($body['lexos_hub_token'] ?? '')),
                trim((string) ($body['lexos_hub_refresh_token'] ?? '')),
                $hubContext,
            );
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

if ($page === 'protheus-monitor-medidos') {
    $params = $_GET;
    $params['page'] = 'protheus-monitor-romaneio';
    header('Location: ' . portal_wct_public_path($baseUrl, 'index.php?' . http_build_query($params)), true, 301);
    exit;
}
$allowedPages = [
    'dashboard',
    'api-config',
    'lexos-hub-connect',
    'orders',
    'monitor-pedidos',
    'lexos-diagnostico-expedicao',
    'lexos-transportadoras',
    'tracking-reprocess',
    'repasse-mp',
    'message-template',
    'manual-send',
    'ml-dashboard',
    'ml-ads-report',
    'ml-catalogos',
    'ml-campanhas',
    'ml-campanhas-pendentes',
    'ml-campanhas-ativas',
    'ml-anuncios-inativos',
    'ml-redimensionar',
    'wct-code-dashboard',
    'wct-code-campanhas',
    'wct-code-campanhas-pendentes',
    'wct-code-campanhas-ativas',
    'wct-code-inactive-ads',
    'wct-code-anuncios',
    'wct-code-images',
    'wct-code-frete',
    'protheus-config',
    'protheus-monitor-romaneio',
    'protheus-monitor-pedidos',
    'protheus-monitor-nfe',
    'protheus-consulta-edi',
    'protheus-monitor-pedidos-erro',
    'protheus-consulta-sql',
];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

if (in_array($page, ['dashboard', 'ml-dashboard'], true)) {
    $app['lexosHubSessionService']->maintainHubSessionSilently();
}

if (
    $page === 'api-config'
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['form_type'] ?? '') === 'lexos_hub_capture'
    && ($_POST['lexos_hub_silent'] ?? '') === '1'
) {
    header('Content-Type: text/plain; charset=utf-8');
    try {
        $hubToken = trim((string) ($_POST['lexos_hub_token'] ?? ''));
        $hubRefresh = trim((string) ($_POST['lexos_hub_refresh_token'] ?? ''));
        if ($hubToken === '' && $hubRefresh === '' && trim((string) ($_POST['lexos_hub_storage'] ?? '')) === '') {
            throw new RuntimeException('missing token');
        }
        $hubContext = $app['lexosHubBrowserCacheService']->parseHubContextFromRequest($_POST);
        $app['lexosHubBrowserCacheService']->saveFromBrowser($hubToken, $hubRefresh, $hubContext);
        echo 'ok';
    } catch (Throwable $e) {
        http_response_code(400);
        echo 'err: ' . $e->getMessage();
    }
    exit;
}

if ($page === 'protheus-consulta-sql' && isset($_GET['protheus_sql_action']) && $_GET['protheus_sql_action'] !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string) $_GET['protheus_sql_action'];

    try {
        $service = $app['protheusAdHocQueryService'];
        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        if ($action === 'run_raw' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $sql = (string) ($body['sql'] ?? '');
            $result = $service->runRawQuery(
                $sql,
                isset($body['query_id']) ? (string) $body['query_id'] : null
            );

            $historyId = $app['protheusSqlQueryHistoryRepository']->save([
                'table' => \App\Services\ProtheusAdHocQueryService::RAW_QUERY_MARKER,
                'columns' => '*',
                'where' => '',
                'order_by' => '',
                'top' => 0,
                'sql' => (string) ($result['sql'] ?? ''),
                'row_count' => (int) ($result['row_count'] ?? 0),
                'elapsed_ms' => (int) ($result['elapsed_ms'] ?? 0),
            ]);
            $result['history_id'] = $historyId;

            portal_wct_echo_json($result);

            exit;
        }

        if ($action === 'run' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $table = (string) ($body['table'] ?? '');
            $where = (string) ($body['where'] ?? '');
            $columns = (string) ($body['columns'] ?? '*');
            $top = (int) ($body['top'] ?? \App\Services\ProtheusAdHocQueryService::DEFAULT_TOP);
            $orderBy = (string) ($body['order_by'] ?? '');
            $countOnly = !empty($body['count_only']);

            $result = $service->runQuery(
                $table,
                $where,
                $columns,
                $top,
                isset($body['query_id']) ? (string) $body['query_id'] : null,
                $orderBy,
                $countOnly
            );

            $historyId = $app['protheusSqlQueryHistoryRepository']->save([
                'table' => $table,
                'columns' => $countOnly ? \App\Services\ProtheusAdHocQueryService::COUNT_COLUMNS_MARKER : $columns,
                'where' => $where,
                'order_by' => $orderBy,
                'top' => $top,
                'sql' => (string) ($result['sql'] ?? ''),
                'row_count' => (int) ($result['row_count'] ?? 0),
                'elapsed_ms' => (int) ($result['elapsed_ms'] ?? 0),
            ]);
            $result['history_id'] = $historyId;

            portal_wct_echo_json($result);

            exit;
        }

        if ($action === 'cancel' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            echo json_encode(
                $service->cancelQuery((string) ($body['query_id'] ?? '')),
                JSON_UNESCAPED_UNICODE
            );

            exit;
        }

        if ($action === 'tables' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            echo json_encode(
                $service->listTables(
                    isset($_GET['q']) ? (string) $_GET['q'] : null,
                    isset($_GET['limit']) ? (int) $_GET['limit'] : \App\Services\ProtheusAdHocQueryService::MAX_TABLES_LIST
                ),
                JSON_UNESCAPED_UNICODE
            );

            exit;
        }

        if ($action === 'columns' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            echo json_encode(
                $service->listTableColumns((string) ($_GET['table'] ?? '')),
                JSON_UNESCAPED_UNICODE
            );

            exit;
        }

        if ($action === 'history' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            echo json_encode([
                'ok' => true,
                'items' => $app['protheusSqlQueryHistoryRepository']->listRecent(50),
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($action === 'history_item' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            $item = $app['protheusSqlQueryHistoryRepository']->findById((int) ($_GET['id'] ?? 0));
            if ($item === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Historico nao encontrado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($action === 'saved_queries' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            echo json_encode([
                'ok' => true,
                'items' => $app['protheusSqlSavedQueriesRepository']->listAll(),
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($action === 'saved_query_item' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
            $item = $app['protheusSqlSavedQueriesRepository']->findById((int) ($_GET['id'] ?? 0));
            if ($item === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Query salva nao encontrada.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($action === 'saved_query_save' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $title = (string) ($body['title'] ?? '');
            $sql = (string) ($body['sql'] ?? '');
            $service->validateRawSql($sql);
            $id = $app['protheusSqlSavedQueriesRepository']->save($title, $sql);
            echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($action === 'saved_query_delete' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $id = (int) ($body['id'] ?? 0);
            $deleted = $app['protheusSqlSavedQueriesRepository']->deleteById($id);
            if (!$deleted) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Query salva nao encontrada.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);

            exit;
        }

        if ($action === 'history_delete' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $id = (int) ($body['id'] ?? 0);
            $deleted = $app['protheusSqlQueryHistoryRepository']->deleteById($id);
            if (!$deleted) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Registro nao encontrado.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);

            exit;
        }

        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo ou acao invalidos.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($page === 'protheus-consulta-sql' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        if (!$app['protheusConnectionService']->isDriverAvailable()) {
            throw new RuntimeException('Driver SQL Server nao disponivel neste PHP.');
        }
        if ($app['protheusSettingsRepository']->getSettings() === null) {
            throw new RuntimeException('Configure o Protheus antes de exportar.');
        }

        $historyRepo = $app['protheusSqlQueryHistoryRepository'];
        $historyId = (int) ($_GET['history_id'] ?? 0);
        if ($historyId > 0) {
            $item = $historyRepo->findById($historyId);
            if ($item === null) {
                throw new RuntimeException('Historico da consulta nao encontrado.');
            }
            if ($item['table'] === \App\Services\ProtheusAdHocQueryService::RAW_QUERY_MARKER) {
                $filePath = $app['protheusAdHocQueryService']->exportRawToXlsx((string) $item['sql']);
                $fileName = basename($filePath);
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $fileName . '"');
                header('Content-Length: ' . (string) filesize($filePath));
                readfile($filePath);
                @unlink($filePath);
                exit;
            }
            $table = (string) $item['table'];
            $where = (string) $item['where'];
            $columns = (string) $item['columns'];
            $top = (int) $item['top'];
            $orderBy = (string) ($item['order_by'] ?? '');
            $countOnly = ((string) ($item['columns'] ?? '')) === \App\Services\ProtheusAdHocQueryService::COUNT_COLUMNS_MARKER;
        } else {
            $table = (string) ($_GET['table'] ?? '');
            $where = (string) ($_GET['where'] ?? '');
            $columns = (string) ($_GET['columns'] ?? '*');
            $top = (int) ($_GET['top'] ?? \App\Services\ProtheusAdHocQueryService::DEFAULT_TOP);
            $orderBy = (string) ($_GET['order_by'] ?? '');
            $countOnly = ((string) ($_GET['columns'] ?? '')) === \App\Services\ProtheusAdHocQueryService::COUNT_COLUMNS_MARKER
                || !empty($_GET['count_only']);
        }

        $filePath = $app['protheusAdHocQueryService']->exportToXlsx($table, $where, $columns, $top, $orderBy, $countOnly);
        $fileName = basename($filePath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Erro na exportacao: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

if ($page === 'repasse-mp' && isset($_GET['repasse_action']) && $_GET['repasse_action'] !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $jobId = trim((string) ($_POST['job'] ?? $_GET['job'] ?? ''));
    $action = (string) $_GET['repasse_action'];

    try {
        if ($action === 'chunk' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            echo json_encode($app['repasseMpService']->processJobChunk($jobId), JSON_UNESCAPED_UNICODE);

            exit;
        }
        if ($action === 'finalize' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            echo json_encode($app['repasseMpService']->finalizeJob($jobId), JSON_UNESCAPED_UNICODE);

            exit;
        }
        if ($action === 'status') {
            echo json_encode($app['repasseMpService']->getJobStatus($jobId), JSON_UNESCAPED_UNICODE);

            exit;
        }
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo ou acao invalidos.'], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$menuSections = [
    'Mercado Livre' => [
        ['id' => 'ml-dashboard', 'label' => 'Dashboard'],
        ['id' => 'api-config', 'label' => 'Configuração API'],
        ['id' => 'orders', 'label' => 'Pedidos'],
        ['id' => 'ml-catalogos', 'label' => 'Catálogos'],
        ['id' => 'ml-campanhas', 'label' => 'Campanhas'],
        ['id' => 'ml-campanhas-pendentes', 'label' => 'Campanhas pendentes'],
        ['id' => 'ml-campanhas-ativas', 'label' => 'Campanhas ativas'],
        ['id' => 'ml-anuncios-inativos', 'label' => 'Anúncios inativos'],
        ['id' => 'ml-ads-report', 'label' => 'Relatório de anúncios'],
        ['id' => 'ml-redimensionar', 'label' => 'Redimensionar imagens'],
        ['id' => 'message-template', 'label' => 'Mensageria ML'],
    ],
    'Lexos' => [
        ['id' => 'dashboard', 'label' => 'Dashboard'],
        ['id' => 'monitor-pedidos', 'label' => 'Monitor de pedidos'],
        ['id' => 'lexos-transportadoras', 'label' => 'Transportadoras'],
        ['id' => 'lexos-diagnostico-expedicao', 'label' => 'Diagnóstico expedição'],
        [
            'id' => 'lexos-hub-expedicao',
            'label' => 'Lexos Hub (expedição)',
            'external' => true,
            'href' => 'https://app-hub.lexos.com.br/#/expedicao/entrega/lista/?aba=Todos',
        ],
    ],
    'Mercado Pago' => [
        ['id' => 'repasse-mp', 'label' => 'Repasse MP'],
    ],
    'Protheus' => [
        ['id' => 'protheus-config', 'label' => 'Config Protheus'],
        ['id' => 'protheus-monitor-romaneio', 'label' => 'Monitor de Romaneio'],
        ['id' => 'protheus-monitor-pedidos', 'label' => 'Monitor de Pedidos'],
        ['id' => 'protheus-monitor-nfe', 'label' => 'Monitor NF-e SEFAZ'],
        ['id' => 'protheus-consulta-edi', 'label' => 'Monitor EDI'],
        ['id' => 'protheus-monitor-pedidos-erro', 'label' => 'Erros Pedidos ZA4'],
        ['id' => 'protheus-consulta-sql', 'label' => 'Consulta SQL'],
    ],
    'Integração' => [
        [
            'id' => 'tracking-wct',
            'label' => 'Tracking WCT',
            'external' => true,
            'href' => $trackingWctUrl,
        ],
        ['id' => 'tracking-reprocess', 'label' => 'Forçar integração Tracking'],
    ],
    'WCT CODE' => [
        ['id' => 'wct-code-dashboard', 'label' => 'Dashboard (backup cPanel)'],
        ['id' => 'wct-code-campanhas', 'label' => 'Campanhas'],
        ['id' => 'wct-code-campanhas-pendentes', 'label' => 'Campanhas pendentes'],
        ['id' => 'wct-code-campanhas-ativas', 'label' => 'Campanhas ativas'],
        ['id' => 'wct-code-inactive-ads', 'label' => 'Anúncios inativos'],
        ['id' => 'wct-code-anuncios', 'label' => 'Relatório de anúncios'],
        ['id' => 'wct-code-images', 'label' => 'Redimensionar imagens'],
        ['id' => 'wct-code-frete', 'label' => 'Relatório de frete'],
    ],
];

if ($page === 'repasse-mp' && isset($_GET['download']) && $_GET['download'] !== '') {
    $jobId = isset($_GET['job']) ? trim((string) $_GET['job']) : '';
    $filePath = $app['repasseMpService']->getExportFilePathForDownload(
        (string) $_GET['download'],
        $jobId !== '' ? $jobId : null
    );
    if (!$filePath) {
        http_response_code(404);
        echo 'Arquivo não encontrado.';
        exit;
    }

    $fileName = basename($filePath);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . (string) filesize($filePath));
    readfile($filePath);
    exit;
}

if ($page === 'protheus-monitor-nfe' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        $settings = $app['protheusSettingsRepository']->getSettings();
        if ($settings === null) {
            throw new RuntimeException('Configure o Protheus antes de exportar.');
        }
        if (!$app['protheusConnectionService']->isDriverAvailable()) {
            throw new RuntimeException('Driver SQL Server nao disponivel neste PHP.');
        }

        $dataCorte = \App\Repositories\ProtheusSettingsRepository::resolveDataCorte($settings);
        $filial = trim((string) ($_GET['filial'] ?? '0101'));
        $emissaoDe = trim((string) ($_GET['emissao_de'] ?? $dataCorte));
        $emissaoAte = trim((string) ($_GET['emissao_ate'] ?? date('Y-m-d')));
        $statusFilter = strtolower(trim((string) ($_GET['status_sefaz'] ?? '')));
        $marketplace = trim((string) ($_GET['marketplace'] ?? ''));
        $filterPedMarketplace = trim((string) ($_GET['ped_marketplace'] ?? ''));
        if ($emissaoDe === '') {
            $emissaoDe = $dataCorte;
        }
        if ($emissaoDe < $dataCorte) {
            $emissaoDe = $dataCorte;
        }

        $filePath = $app['protheusNfeMonitorService']->exportToXlsx(
            $filial,
            $emissaoDe,
            $emissaoAte,
            $statusFilter,
            $marketplace,
            $filterPedMarketplace
        );
        $fileName = basename($filePath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Erro na exportacao: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

if ($page === 'protheus-monitor-pedidos-erro' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        $settings = $app['protheusSettingsRepository']->getSettings();
        if ($settings === null) {
            throw new RuntimeException('Configure o Protheus antes de exportar.');
        }
        if (!$app['protheusConnectionService']->isDriverAvailable()) {
            throw new RuntimeException('Driver SQL Server nao disponivel neste PHP.');
        }

        $dataCorte = \App\Repositories\ProtheusSettingsRepository::resolveDataCorte($settings);
        $filial = trim((string) ($_GET['filial'] ?? '0101'));
        $dataDe = trim((string) ($_GET['data_de'] ?? $dataCorte));
        $dataAte = trim((string) ($_GET['data_ate'] ?? date('Y-m-d')));
        if ($dataDe === '') {
            $dataDe = $dataCorte;
        }
        if ($dataDe < $dataCorte) {
            $dataDe = $dataCorte;
        }

        $filePath = $app['protheusZa4PedidosErroMonitorService']->exportToXlsx(
            $filial,
            $dataDe,
            $dataAte,
            ($_GET['somente_erro'] ?? '1') !== '0',
            trim((string) ($_GET['idlexo'] ?? '')),
            trim((string) ($_GET['ped_mar'] ?? '')),
            trim((string) ($_GET['texto_erro'] ?? '')),
            trim((string) ($_GET['marketplace'] ?? ''))
        );
        $fileName = basename($filePath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Erro na exportacao: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

if ($page === 'protheus-consulta-edi' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        $settings = $app['protheusSettingsRepository']->getSettings();
        if ($settings === null) {
            throw new RuntimeException('Configure o Protheus antes de exportar.');
        }
        if (!$app['protheusConnectionService']->isDriverAvailable()) {
            throw new RuntimeException('Driver SQL Server nao disponivel neste PHP.');
        }

        $dataCorte = \App\Repositories\ProtheusSettingsRepository::resolveDataCorte($settings);
        $filial = trim((string) ($_GET['filial'] ?? '0101'));
        $dataDe = trim((string) ($_GET['data_de'] ?? $dataCorte));
        $dataAte = trim((string) ($_GET['data_ate'] ?? date('Y-m-d')));
        if ($dataDe === '') {
            $dataDe = $dataCorte;
        }
        if ($dataDe < $dataCorte) {
            $dataDe = $dataCorte;
        }

        $filePath = $app['protheusEdiConsultaService']->exportToXlsx(
            $filial,
            $dataDe,
            $dataAte,
            trim((string) ($_GET['nota_fiscal'] ?? '')),
            trim((string) ($_GET['idlexo'] ?? '')),
            trim((string) ($_GET['ped_mar'] ?? '')),
            trim((string) ($_GET['cod_ocorrencia'] ?? '')),
            trim((string) ($_GET['motivo_ocorrencia'] ?? '')),
            trim((string) ($_GET['status'] ?? ''))
        );
        $fileName = basename($filePath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Erro na exportacao: ' . htmlspecialchars($e->getMessage());
        exit;
    }
}

if ($page === 'protheus-monitor-romaneio' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        $settings = $app['protheusSettingsRepository']->getSettings();
        if ($settings === null) {
            throw new RuntimeException('Configure o Protheus antes de exportar.');
        }
        if (!$app['protheusConnectionService']->isDriverAvailable()) {
            throw new RuntimeException('Driver SQL Server nao disponivel neste PHP.');
        }

        $dataCorte = \App\Repositories\ProtheusSettingsRepository::resolveDataCorte($settings);
        $filial = trim((string) ($_GET['filial'] ?? '0101'));
        $emissaoDe = trim((string) ($_GET['emissao_de'] ?? $dataCorte));
        $emissaoAte = trim((string) ($_GET['emissao_ate'] ?? date('Y-m-d')));
        if ($emissaoDe === '') {
            $emissaoDe = $dataCorte;
        }
        if ($emissaoDe < $dataCorte) {
            $emissaoDe = $dataCorte;
        }
        $marketplace = trim((string) ($_GET['marketplace'] ?? ''));
        $filterDoc = trim((string) ($_GET['doc'] ?? ''));
        $filterPedMarketplace = trim((string) ($_GET['ped_marketplace'] ?? ''));

        $filePath = $app['protheusRomaneioMonitorService']->exportToXlsx(
            $filial,
            $emissaoDe,
            $emissaoAte,
            $marketplace,
            $filterDoc,
            $filterPedMarketplace
        );
        $fileName = basename($filePath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao exportar: ' . $exception->getMessage();
    }
    exit;
}

if ($page === 'protheus-monitor-pedidos' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        $settings = $app['protheusSettingsRepository']->getSettings();
        if ($settings === null) {
            throw new RuntimeException('Configure o Protheus antes de exportar.');
        }
        if (!$app['protheusConnectionService']->isDriverAvailable()) {
            throw new RuntimeException('Driver SQL Server nao disponivel neste PHP.');
        }

        $dataCorte = \App\Repositories\ProtheusSettingsRepository::resolveDataCorte($settings);
        $filial = trim((string) ($_GET['filial'] ?? '0101'));
        $emissaoDe = trim((string) ($_GET['emissao_de'] ?? $dataCorte));
        $emissaoAte = trim((string) ($_GET['emissao_ate'] ?? date('Y-m-d')));
        if ($emissaoDe === '') {
            $emissaoDe = $dataCorte;
        }
        if ($emissaoDe < $dataCorte) {
            $emissaoDe = $dataCorte;
        }
        $marketplace = trim((string) ($_GET['marketplace'] ?? ''));
        $filterDoc = trim((string) ($_GET['doc'] ?? ''));
        $filterPedMarketplace = trim((string) ($_GET['ped_marketplace'] ?? ''));
        $filterCpfCnpj = trim((string) ($_GET['cpf_cnpj'] ?? ''));
        $saidaDe = trim((string) ($_GET['saida_de'] ?? ''));
        $saidaAte = trim((string) ($_GET['saida_ate'] ?? ''));

        $filePath = $app['protheusPedidosMonitorService']->exportToXlsx(
            $filial,
            $emissaoDe,
            $emissaoAte,
            $marketplace,
            $filterDoc,
            $filterPedMarketplace,
            $filterCpfCnpj,
            $saidaDe,
            $saidaAte
        );
        $fileName = basename($filePath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao exportar: ' . $exception->getMessage();
    }
    exit;
}

if (in_array($page, ['dashboard', 'ml-dashboard'], true) && ($_GET['export'] ?? '') === 'xlsx' && ($_GET['lexos_tab'] ?? '') === 'products') {
    try {
        if (!$app['lexosCredentialsService']->hasHubToken()) {
            throw new RuntimeException('Configure o Token Hub (Dashboard) em Configuracao API → Lexos.');
        }
        $dStart = trim((string) ($_GET['lexos_start'] ?? ''));
        $dEnd = trim((string) ($_GET['lexos_end'] ?? ''));
        $search = trim((string) ($_GET['lexos_search'] ?? ''));
        if ($dStart === '' || $dEnd === '') {
            throw new RuntimeException('Informe data inicial e final para exportar.');
        }
        $rows = $app['lexosDashboardService']->exportProductsRows($dStart, $dEnd, $search);
        if ($rows === []) {
            throw new RuntimeException('Nenhum produto encontrado para exportar.');
        }
        require_once __DIR__ . '/app/Lib/SimpleXLSXGen.php';
        $sheet = [['SKU', 'Nome', 'EAN', 'Estoque', 'Faturamento', 'Quantidade', 'Classificacao']];
        foreach ($rows as $row) {
            $sheet[] = $row;
        }
        $fileName = 'produtos_lexos_' . date('Y-m-d_His') . '.xlsx';
        $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($sheet, 'Produtos');
        $xlsx->downloadAs($fileName);
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao exportar: ' . $exception->getMessage();
    }
    exit;
}

if ($page === 'ml-ads-report' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        $limitRaw = trim((string) ($_GET['limit'] ?? '200'));
        $limit = (int) $limitRaw;
        if ($limit < 0) {
            $limit = 0;
        }
        if ($limit > 5000) {
            $limit = 5000;
        }
        $filters = [
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
            'sku' => trim((string) ($_GET['sku'] ?? '')),
            'tipo' => trim((string) ($_GET['tipo'] ?? 'todos')),
        ];
        $result = $app['mlAdsReportService']->generateReport($limit, $filters);
        $filePath = $app['mlAdsReportService']->getExportFilePath((string) ($result['file_name'] ?? ''));
        if ($filePath === null) {
            throw new RuntimeException('Arquivo de exportacao nao encontrado.');
        }
        $fileName = basename($filePath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao exportar: ' . $exception->getMessage();
    }
    exit;
}

if ($page === 'ml-catalogos' && ($_GET['ml_catalog_action'] ?? '') === 'detail') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metodo nao permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    try {
        $result = $app['mlCatalogListService']->fetchCatalogDetail(
            (string) ($body['catalog_product_id'] ?? ''),
            (string) ($body['item_mlb'] ?? '')
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($page === 'ml-catalogos' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        $limitRaw = trim((string) ($_GET['limit'] ?? '200'));
        $limit = (int) $limitRaw;
        if ($limit < 0) {
            $limit = 0;
        }
        if ($limit > 5000) {
            $limit = 5000;
        }
        $filters = [
            'status' => trim((string) ($_GET['status'] ?? 'todos')),
            'sku' => trim((string) ($_GET['sku'] ?? '')),
            'catalog_product_id' => trim((string) ($_GET['catalog_product_id'] ?? '')),
        ];
        $result = $app['mlCatalogListService']->generateReport($limit, $filters);
        $filePath = $app['mlCatalogListService']->getExportFilePath((string) ($result['file_name'] ?? ''));
        if ($filePath === null) {
            throw new RuntimeException('Arquivo de exportacao nao encontrado.');
        }
        $fileName = basename($filePath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao exportar: ' . $exception->getMessage();
    }
    exit;
}

if ($page === 'ml-catalogos' && isset($_GET['download']) && $_GET['download'] !== '') {
    $filePath = $app['mlCatalogListService']->getExportFilePath((string) $_GET['download']);
    if (!$filePath) {
        http_response_code(404);
        echo 'Arquivo nao encontrado.';
        exit;
    }

    $fileName = basename($filePath);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . (string) filesize($filePath));
    readfile($filePath);
    exit;
}

if ($page === 'ml-ads-report' && isset($_GET['download']) && $_GET['download'] !== '') {
    $filePath = $app['mlAdsReportService']->getExportFilePath((string) $_GET['download']);
    if (!$filePath) {
        http_response_code(404);
        echo 'Arquivo não encontrado.';
        exit;
    }

    $fileName = basename($filePath);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . (string) filesize($filePath));
    readfile($filePath);
    exit;
}

// Upload Repasse MP precisa redirecionar antes de qualquer output HTML (evita "headers already sent").
if ($page === 'ml-anuncios-inativos' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        $filePath = $app['mlInactiveAdsService']->exportAllInactiveAds();
        $fileName = basename($filePath);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . (string) filesize($filePath));
        readfile($filePath);
        @unlink($filePath);
    } catch (Throwable $exception) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Erro ao exportar: ' . $exception->getMessage();
    }
    exit;
}

if ($page === 'ml-redimensionar' && isset($_GET['download']) && $_GET['download'] !== '') {
    $filePath = $app['mlImageResizeService']->getZipPath((string) $_GET['download']);
    if (!$filePath) {
        http_response_code(404);
        echo 'Arquivo nao encontrado.';
        exit;
    }

    $fileName = basename($filePath);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . (string) filesize($filePath));
    readfile($filePath);
    @unlink($filePath);
    exit;
}

// Upload Repasse MP precisa redirecionar antes de qualquer output HTML (evita "headers already sent").
if (
    $page === 'repasse-mp'
    && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['form_type'] ?? '') === 'mp_upload'
) {
    try {
        $jobId = $app['repasseMpService']->createJobFromUpload($_FILES['repasse_mp_file'] ?? []);
        header('Location: ' . portal_wct_public_path($baseUrl, 'index.php?page=repasse-mp&job=' . rawurlencode($jobId)), true, 302);
    } catch (Throwable $exception) {
        header(
            'Location: ' . portal_wct_public_path($baseUrl, 'index.php?page=repasse-mp&flash_err=' . rawurlencode($exception->getMessage())),
            true,
            302
        );
    }
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal WCT</title>
    <?php $faviconHref = portal_wct_public_path($baseUrl, 'favicon.svg'); ?>
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($faviconHref, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="shortcut icon" href="<?= htmlspecialchars($faviconHref, ENT_QUOTES, 'UTF-8') ?>">
    <style>
        :root {
            --wct-bg: #f4f5f7;
            --wct-surface: #ffffff;
            --wct-text: #1f2937;
            --wct-muted: #6b7280;
            --wct-sidebar: #111111;
            --wct-sidebar-2: #1a1a1a;
            --wct-primary: #d50000;
            --wct-primary-dark: #b30000;
            --wct-border: #e5e7eb;
        }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; margin: 0; background: var(--wct-bg); color: var(--wct-text); }
        .layout { display: flex; min-height: 100vh; }
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, var(--wct-sidebar) 0%, var(--wct-sidebar-2) 100%);
            color: #e5e7eb;
            padding: 18px 14px;
            border-right: 1px solid rgba(255, 255, 255, .08);
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .brand {
            margin: 4px 8px 16px 8px;
            padding: 4px 0 14px 0;
            border-bottom: 1px solid rgba(255,255,255,.12);
            color: #fff;
        }
        .brand-logo { display: inline-flex; align-items: center; }
        .brand-mark {
            border: 1px solid #f5b700;
            padding: 4px 10px 5px 10px;
            line-height: 1;
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: .5px;
            color: #fff;
        }
        .brand-mark .accent { color: #f5b700; }
        .menu-section-title {
            margin: 14px 0 8px 0;
            font-size: .68rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #f5b700;
            font-weight: bold;
            background: rgba(0, 0, 0, .45);
            border: 1px solid rgba(245, 183, 0, .35);
            border-radius: 6px;
            padding: 7px 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .menu-section-title::before {
            content: "≡";
            font-size: .82rem;
            line-height: 1;
            color: #f5b700;
        }
        .menu-list { list-style: none; margin: 0; padding: 0; }
        .menu-list li { margin: 0; }
        .menu-link {
            display: block;
            text-decoration: none;
            color: #f3f4f6;
            padding: 9px 10px;
            border-radius: 8px;
            margin: 2px 0;
            font-size: .92rem;
            border: 1px solid transparent;
        }
        .menu-link:hover {
            background: rgba(245, 183, 0, .12);
            border-color: rgba(245, 183, 0, .4);
            color: #f5b700;
        }
        .menu-link.active {
            background: #f5b700;
            color: #111111;
            border-color: #f5b700;
            font-weight: bold;
        }
        .content {
            flex: 1;
            min-width: 0;
            padding: 24px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: var(--wct-surface); border-radius: 8px; padding: 20px; margin-top: 20px; box-shadow: 0 2px 8px rgba(0, 0, 0, .06); border: 1px solid var(--wct-border); }
        h1 { margin: 0 0 14px 0; font-size: 1.5rem; }
        label { display: block; margin-top: 12px; font-weight: bold; }
        input, textarea, select { width: 100%; padding: 10px; margin-top: 6px; border: 1px solid #ccd3df; border-radius: 6px; box-sizing: border-box; }
        button {
            margin-top: 16px;
            padding: 10px 16px;
            border: 1px solid #f5b700;
            border-radius: 6px;
            background: #111111;
            color: #f5b700;
            font-weight: bold;
            letter-spacing: .04em;
            text-transform: uppercase;
            cursor: pointer;
        }
        button:hover {
            background: #f5b700;
            color: #111111;
        }
        button:disabled {
            opacity: .55;
            cursor: not-allowed;
            background: #202020;
            color: #d1a93a;
        }
        a { color: var(--wct-primary); }
        .msg { padding: 10px 12px; border-radius: 6px; margin-bottom: 14px; }
        .ok { background: #e7f8ec; color: #0f6d2e; }
        .err { background: #ffe9e9; color: #a12323; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; border-bottom: 1px solid #e8edf5; text-align: left; font-size: .9rem; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: .75rem; color: #fff; }
        .sent { background: #16a34a; }
        .error { background: #dc2626; }
        .subnav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            background: #111111;
            border: 1px solid rgba(245, 183, 0, .22);
            border-radius: 8px;
            padding: 6px;
        }
        .subnav a {
            text-decoration: none;
            background: transparent;
            border: 1px solid transparent;
            color: #f3f4f6;
            border-radius: 999px;
            padding: 8px 13px;
            font-size: .78rem;
            letter-spacing: .06em;
            text-transform: uppercase;
            font-weight: bold;
        }
        .subnav a:hover { border-color: rgba(245, 183, 0, .45); color: #f5b700; }
        .subnav a.active {
            background: rgba(245, 183, 0, .12);
            border-color: #f5b700;
            color: #f5b700;
            font-weight: bold;
        }
        .subnav-sticky {
            position: sticky;
            top: 0;
            z-index: 900;
            background: var(--wct-bg);
            padding: 6px 0 10px 0;
        }

        /* Monitor Protheus: tabela larga, usa toda a area ao lado do menu */
        body.page-protheus-monitor-full .container {
            max-width: none;
            width: 100%;
            margin: 0;
        }
        body.page-protheus-monitor-full .content {
            padding: 10px 12px 18px;
        }
        body.page-protheus-monitor-full .card {
            margin-top: 10px;
            padding: 12px 14px;
        }
        body.page-protheus-monitor-full .protheus-monitor-card {
            width: 100%;
            max-width: 100%;
        }
        body.page-protheus-monitor-full .protheus-monitor-card h1 {
            font-size: 1.25rem;
            margin-bottom: 8px;
        }
        body.page-protheus-monitor-full .protheus-monitor-card > p {
            margin: 0 0 8px 0;
            font-size: .88rem;
        }
        body.page-protheus-monitor-full .protheus-legend {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        body.page-protheus-monitor-full .legend-item {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 6px;
            font-size: .8rem;
            font-weight: bold;
        }
        body.page-protheus-monitor-full .protheus-filters .filter-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px 14px;
            margin-top: 6px;
            align-items: end;
        }
        body.page-protheus-monitor-full .protheus-filters label {
            margin-top: 0;
            font-weight: bold;
            font-size: .85rem;
        }
        body.page-protheus-monitor-full .protheus-filters input,
        body.page-protheus-monitor-full .protheus-filters select {
            margin-top: 4px;
        }
        body.page-protheus-monitor-full .protheus-filters button {
            margin-top: 0;
            width: 100%;
        }
        body.page-protheus-monitor-full .protheus-summary-row {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 12px 0 6px;
        }
        body.page-protheus-monitor-full .protheus-summary {
            margin: 0;
            color: var(--wct-muted);
            font-size: .88rem;
        }
        body.page-protheus-monitor-full a.btn-export-xlsx,
        body.page-protheus-monitor-full button.btn-export-xlsx-submit {
            display: inline-block;
            padding: 9px 14px;
            border-radius: 6px;
            border: 1px solid #f5b700;
            background: #111111;
            color: #f5b700;
            font-weight: bold;
            font-size: .78rem;
            letter-spacing: .04em;
            text-transform: uppercase;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            font-family: inherit;
        }
        body.page-protheus-monitor-full a.btn-export-xlsx:hover,
        body.page-protheus-monitor-full button.btn-export-xlsx-submit:hover:not(:disabled) {
            background: #f5b700;
            color: #111111;
        }
        body.page-protheus-monitor-full .table-wrap {
            width: 100%;
            max-width: 100%;
            overflow: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid var(--wct-border);
            border-radius: 6px;
            margin-top: 6px;
            max-height: min(70vh, 720px);
        }
        body.page-protheus-monitor-full .protheus-table {
            width: max-content;
            min-width: 100%;
            border-collapse: collapse;
            font-size: .78rem;
            line-height: 1.35;
        }
        body.page-protheus-monitor-full .protheus-table th,
        body.page-protheus-monitor-full .protheus-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #e8edf5;
            text-align: left;
            vertical-align: top;
        }
        body.page-protheus-monitor-full .protheus-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f1f5f9;
            white-space: nowrap;
            font-size: .75rem;
            text-transform: uppercase;
        }
        body.page-protheus-monitor-full .protheus-table tbody tr:hover {
            background: #f8fafc;
        }
        @media (max-width: 1100px) {
            body.page-protheus-monitor-full .protheus-filters .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 920px) {
            .layout { display: block; }
            .sidebar {
                width: auto;
                height: auto;
                position: static;
                padding: 10px 12px;
            }
            .menu-section-title { margin-top: 10px; }
            .content { padding: 14px; }
        }

        body.ml-is-loading { overflow: hidden; }
        .ml-loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 4000;
            background: rgba(15, 23, 42, .42);
            align-items: center;
            justify-content: center;
        }
        .ml-loading-overlay.is-open { display: flex; }
        .ml-loading-panel {
            background: #fff;
            border-radius: 12px;
            padding: 22px 28px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, .18);
            text-align: center;
            min-width: 220px;
        }
        .ml-loading-spinner {
            width: 42px;
            height: 42px;
            margin: 0 auto 12px;
            border: 4px solid #e2e8f0;
            border-top-color: #2563eb;
            border-radius: 50%;
            animation: ml-spin .85s linear infinite;
        }
        @keyframes ml-spin {
            to { transform: rotate(360deg); }
        }
        .ml-loading-text {
            margin: 0;
            font-size: .95rem;
            color: #334155;
            font-weight: 600;
        }
        .feedback.ok, .msg.ok { color: #166534; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 10px 12px; border-radius: 8px; }
        .feedback.err, .msg.err { color: #991b1b; background: #fef2f2; border: 1px solid #fecaca; padding: 10px 12px; border-radius: 8px; }
        body.page-wct-code-full .container { max-width: none; padding: 0; margin: 0; }
        body.page-wct-code-full .content { padding: 0; }
        body.page-wct-code-full .layout { min-height: 100vh; }
        .wct-code-shell { height: calc(100vh - 0px); margin: 0; }
        .wct-code-shell__frame { width: 100%; height: calc(100vh - 8px); min-height: calc(100vh - 8px); border: 0; display: block; background: #fff; }
    </style>
</head>
<body class="<?= in_array($page, ['protheus-monitor-romaneio', 'protheus-monitor-pedidos', 'protheus-monitor-nfe', 'protheus-consulta-edi', 'protheus-monitor-pedidos-erro', 'protheus-consulta-sql', 'ml-dashboard', 'ml-ads-report', 'ml-catalogos', 'ml-campanhas', 'ml-campanhas-pendentes', 'ml-campanhas-ativas', 'ml-anuncios-inativos', 'ml-redimensionar'], true) ? 'page-protheus-monitor-full' : '' ?><?= $isWctCodeModulePage ? ' page-wct-code-full' : '' ?>">
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-logo" aria-label="WCT Fitness">
                <span class="brand-mark"><span class="accent">W</span>CT</span>
            </div>
        </div>
        <?php foreach ($menuSections as $sectionTitle => $items): ?>
            <div class="menu-section-title"><?= htmlspecialchars($sectionTitle) ?></div>
            <ul class="menu-list">
                <?php foreach ($items as $item): ?>
                    <?php
                    $isExternal = !empty($item['external']);
                    $itemHref = $isExternal ? (string) ($item['href'] ?? '#') : portal_wct_public_path($baseUrl, 'index.php?page=' . urlencode($item['id']));
                    $isActive = !$isExternal && $page === $item['id'];
                    ?>
                    <li>
                        <a class="menu-link<?= $isActive ? ' active' : '' ?>" href="<?= htmlspecialchars($itemHref) ?>"<?= $isExternal ? ' rel="noopener"' : '' ?>>
                            <?= htmlspecialchars($item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </aside>

    <main class="content">
        <div class="container">
            <?php if ($page === 'message-template' || $page === 'manual-send'): ?>
                <div class="subnav-sticky">
                    <nav class="subnav">
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=message-template')) ?>" class="<?= $page === 'message-template' ? 'active' : '' ?>">Mensagem</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=manual-send')) ?>" class="<?= $page === 'manual-send' ? 'active' : '' ?>">Mensagem manual</a>
                    </nav>
                </div>
            <?php endif; ?>
            <?php if (in_array($page, ['dashboard', 'monitor-pedidos', 'lexos-transportadoras', 'lexos-diagnostico-expedicao'], true)): ?>
                <div class="subnav-sticky">
                    <nav class="subnav">
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=dashboard')) ?>" class="<?= $page === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=monitor-pedidos')) ?>" class="<?= $page === 'monitor-pedidos' ? 'active' : '' ?>">Monitor de pedidos</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=lexos-transportadoras')) ?>" class="<?= $page === 'lexos-transportadoras' ? 'active' : '' ?>">Transportadoras</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=lexos-diagnostico-expedicao')) ?>" class="<?= $page === 'lexos-diagnostico-expedicao' ? 'active' : '' ?>">Diagnóstico expedição</a>
                    </nav>
                </div>
            <?php endif; ?>
            <?php if (in_array($page, ['protheus-config', 'protheus-monitor-romaneio', 'protheus-monitor-pedidos', 'protheus-monitor-nfe', 'protheus-consulta-edi', 'protheus-monitor-pedidos-erro', 'protheus-consulta-sql'], true)): ?>
                <div class="subnav-sticky">
                    <nav class="subnav">
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-config')) ?>" class="<?= $page === 'protheus-config' ? 'active' : '' ?>">Config Protheus</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-monitor-romaneio')) ?>" class="<?= $page === 'protheus-monitor-romaneio' ? 'active' : '' ?>">Monitor de Romaneio</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-monitor-pedidos')) ?>" class="<?= $page === 'protheus-monitor-pedidos' ? 'active' : '' ?>">Monitor de Pedidos</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-monitor-nfe')) ?>" class="<?= $page === 'protheus-monitor-nfe' ? 'active' : '' ?>">Monitor NF-e SEFAZ</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-consulta-edi')) ?>" class="<?= $page === 'protheus-consulta-edi' ? 'active' : '' ?>">Monitor EDI</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-monitor-pedidos-erro')) ?>" class="<?= $page === 'protheus-monitor-pedidos-erro' ? 'active' : '' ?>">Erros Pedidos ZA4</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-consulta-sql')) ?>" class="<?= $page === 'protheus-consulta-sql' ? 'active' : '' ?>">Consulta SQL</a>
                    </nav>
                </div>
            <?php endif; ?>
            <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
        </div>
    </main>
</div>
<?php if ($isMlModulePage): ?>
<div id="ml-loading-overlay" class="ml-loading-overlay is-open" aria-live="polite" aria-busy="true">
    <div class="ml-loading-panel">
        <div class="ml-loading-spinner" role="status" aria-hidden="true"></div>
        <p class="ml-loading-text" id="ml-loading-text">Carregando…</p>
    </div>
</div>
<script src="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'assets/js/ml-loading-overlay.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
</body>
</html>
