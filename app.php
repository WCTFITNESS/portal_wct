<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\MercadoPagoSettingsRepository;
use App\Repositories\MessageLogRepository;
use App\Repositories\MessageTemplateRepository;
use App\Repositories\RequestLogRepository;
use App\Repositories\LexosOrderWebhookRepository;
use App\Repositories\ProtheusSettingsRepository;
use App\Repositories\ProtheusSqlQueryHistoryRepository;
use App\Repositories\ProtheusSqlSavedQueriesRepository;
use App\Repositories\RepasseMpJobRepository;
use App\Core\TrackingDatabase;
use App\Repositories\SettingsRepository;
use App\Repositories\TokenRepository;
use App\Repositories\TrackingLexosTokenRepository;
use App\Services\MercadoPagoClient;
use App\Services\MercadoPagoPaymentService;
use App\Services\MercadoLivreClient;
use App\Services\MercadoLivreOAuthService;
use App\Services\LexosDashboardService;
use App\Services\LexosAuthService;
use App\Services\LexosCredentialsService;
use App\Services\LexosHubApiClient;
use App\Services\LexosExpeditionDiagnosticService;
use App\Services\LexosTransportadoraService;
use App\Services\LexosOrderMonitorService;
use App\Services\LexosOrderWebhookService;
use App\Services\MercadoLivreOrderMonitorService;
use App\Services\MessageService;
use App\Services\MlAdsReportService;
use App\Services\MlCatalogListService;
use App\Services\MlPromotionsService;
use App\Services\MlInactiveAdsService;
use App\Services\MlImageResizeService;
use App\Services\OrderService;
use App\Services\ProtheusConnectionService;
use App\Services\ProtheusRomaneioMonitorService;
use App\Services\ProtheusPedidosMonitorService;
use App\Services\ProtheusNfeMonitorService;
use App\Services\ProtheusEdiConsultaService;
use App\Services\ProtheusZa4PedidosErroMonitorService;
use App\Services\ProtheusAdHocQueryService;
use App\Services\RepasseService;
use App\Services\RepasseMpService;
use App\Services\TokenService;

require __DIR__ . '/bootstrap.php';

$database = new Database($config['db']);
$pdo = $database->pdo();

$settingsRepository = new SettingsRepository($pdo);
$mercadopagoSettingsRepository = new MercadoPagoSettingsRepository($pdo);
$tokenRepository = new TokenRepository($pdo);
$templateRepository = new MessageTemplateRepository($pdo);
$logRepository = new MessageLogRepository($pdo);
$requestLogRepository = new RequestLogRepository($pdo);
$repasseMpJobRepository = new RepasseMpJobRepository($pdo);
$protheusSettingsRepository = new ProtheusSettingsRepository($pdo);
$protheusSqlQueryHistoryRepository = new ProtheusSqlQueryHistoryRepository($pdo);
$protheusSqlSavedQueriesRepository = new ProtheusSqlSavedQueriesRepository($pdo);
$client = new MercadoLivreClient($requestLogRepository);
$mercadoLivreOAuthService = new MercadoLivreOAuthService($requestLogRepository);
$mercadopagoClient = new MercadoPagoClient($requestLogRepository);
$tokenService = new TokenService($settingsRepository, $tokenRepository, $client);
$messageService = new MessageService($tokenService, $client, $logRepository);
$orderService = new OrderService($tokenService, $client, $settingsRepository, $templateRepository, $messageService);
$repasseService = new RepasseService($orderService);
$mercadopagoPaymentService = new MercadoPagoPaymentService($mercadopagoSettingsRepository, $mercadopagoClient);
$repasseMpService = new RepasseMpService($mercadopagoPaymentService, $repasseMpJobRepository);
$mlAdsReportService = new MlAdsReportService($tokenService, $client, $settingsRepository);
$mlCatalogListService = new MlCatalogListService($tokenService, $client, $settingsRepository);
$mlPromotionsService = new MlPromotionsService($tokenService, $client, $settingsRepository);
$mlInactiveAdsService = new MlInactiveAdsService($tokenService, $client, $settingsRepository);
$mlImageResizeService = new MlImageResizeService();
$trackingDatabaseUrl = static function () use ($settingsRepository): string {
    $cfg = $settingsRepository->getApiConfig() ?? [];
    $portalUrl = trim((string) ($cfg['tracking_database_url'] ?? ''));
    $env = getenv('TRACKING_DATABASE_URL');
    $envUrl = is_string($env) ? trim($env) : '';

    if ($portalUrl !== '' && TrackingDatabase::hasValidCredentials($portalUrl)) {
        return $portalUrl;
    }
    if ($envUrl !== '') {
        return $envUrl;
    }

    return $portalUrl;
};
$trackingLexosTokenRepository = new TrackingLexosTokenRepository(
    new TrackingDatabase($trackingDatabaseUrl())
);
$lexosCredentialsService = new LexosCredentialsService($settingsRepository, $trackingLexosTokenRepository);
$lexosAuthService = new LexosAuthService(
    $settingsRepository,
    $lexosCredentialsService,
    $trackingLexosTokenRepository
);
$lexosHubApiClient = new LexosHubApiClient($lexosCredentialsService, $lexosAuthService);
$lexosDashboardService = new LexosDashboardService($lexosHubApiClient);
$lexosOrderWebhookRepository = new LexosOrderWebhookRepository($pdo);
$lexosOrderWebhookService = new LexosOrderWebhookService($lexosOrderWebhookRepository);
$mercadoLivreOrderMonitorService = new MercadoLivreOrderMonitorService($orderService);
$lexosOrderMonitorService = new LexosOrderMonitorService($lexosHubApiClient);
$lexosExpeditionDiagnosticService = new LexosExpeditionDiagnosticService($lexosHubApiClient);
$lexosTransportadoraService = new LexosTransportadoraService($lexosHubApiClient);
$protheusConnectionService = new ProtheusConnectionService($protheusSettingsRepository);
$protheusRomaneioMonitorService = new ProtheusRomaneioMonitorService($protheusConnectionService);
$protheusPedidosMonitorService = new ProtheusPedidosMonitorService($protheusConnectionService);
$protheusNfeMonitorService = new ProtheusNfeMonitorService($protheusConnectionService);
$protheusEdiConsultaService = new ProtheusEdiConsultaService($protheusConnectionService);
$protheusZa4PedidosErroMonitorService = new ProtheusZa4PedidosErroMonitorService($protheusConnectionService);
$protheusAdHocQueryService = new ProtheusAdHocQueryService($protheusConnectionService);

return [
    'config' => $config,
    'mercadoLivreClient' => $client,
    'mercadoLivreOAuthService' => $mercadoLivreOAuthService,
    'settingsRepository' => $settingsRepository,
    'tokenRepository' => $tokenRepository,
    'templateRepository' => $templateRepository,
    'logRepository' => $logRepository,
    'requestLogRepository' => $requestLogRepository,
    'tokenService' => $tokenService,
    'messageService' => $messageService,
    'orderService' => $orderService,
    'repasseService' => $repasseService,
    'repasseMpService' => $repasseMpService,
    'mlAdsReportService' => $mlAdsReportService,
    'mlCatalogListService' => $mlCatalogListService,
    'mlPromotionsService' => $mlPromotionsService,
    'mlInactiveAdsService' => $mlInactiveAdsService,
    'mlImageResizeService' => $mlImageResizeService,
    'lexosAuthService' => $lexosAuthService,
    'lexosCredentialsService' => $lexosCredentialsService,
    'lexosHubApiClient' => $lexosHubApiClient,
    'trackingLexosTokenRepository' => $trackingLexosTokenRepository,
    'mercadopagoSettingsRepository' => $mercadopagoSettingsRepository,
    'mercadopagoClient' => $mercadopagoClient,
    'mercadopagoPaymentService' => $mercadopagoPaymentService,
    'lexosDashboardService' => $lexosDashboardService,
    'lexosOrderWebhookService' => $lexosOrderWebhookService,
    'mercadoLivreOrderMonitorService' => $mercadoLivreOrderMonitorService,
    'lexosOrderMonitorService' => $lexosOrderMonitorService,
    'lexosExpeditionDiagnosticService' => $lexosExpeditionDiagnosticService,
    'lexosTransportadoraService' => $lexosTransportadoraService,
    'protheusSettingsRepository' => $protheusSettingsRepository,
    'protheusSqlQueryHistoryRepository' => $protheusSqlQueryHistoryRepository,
    'protheusSqlSavedQueriesRepository' => $protheusSqlSavedQueriesRepository,
    'protheusConnectionService' => $protheusConnectionService,
    'protheusRomaneioMonitorService' => $protheusRomaneioMonitorService,
    'protheusPedidosMonitorService' => $protheusPedidosMonitorService,
    'protheusNfeMonitorService' => $protheusNfeMonitorService,
    'protheusEdiConsultaService' => $protheusEdiConsultaService,
    'protheusZa4PedidosErroMonitorService' => $protheusZa4PedidosErroMonitorService,
    'protheusAdHocQueryService' => $protheusAdHocQueryService,
];
