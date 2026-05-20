<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\MercadoPagoSettingsRepository;
use App\Repositories\MessageLogRepository;
use App\Repositories\MessageTemplateRepository;
use App\Repositories\RequestLogRepository;
use App\Repositories\LexosOrderWebhookRepository;
use App\Repositories\ProtheusSettingsRepository;
use App\Repositories\RepasseMpJobRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TokenRepository;
use App\Services\MercadoPagoClient;
use App\Services\MercadoPagoPaymentService;
use App\Services\MercadoLivreClient;
use App\Services\MercadoLivreOAuthService;
use App\Services\LexosDashboardService;
use App\Services\LexosAuthService;
use App\Services\LexosHubApiClient;
use App\Services\LexosOrderMonitorService;
use App\Services\LexosOrderWebhookService;
use App\Services\MercadoLivreOrderMonitorService;
use App\Services\MessageService;
use App\Services\MlAdsReportService;
use App\Services\OrderService;
use App\Services\ProtheusConnectionService;
use App\Services\ProtheusMedidosMonitorService;
use App\Services\ProtheusNfeMonitorService;
use App\Services\ProtheusEdiConsultaService;
use App\Services\ProtheusZa4PedidosErroMonitorService;
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
$lexosAuthService = new LexosAuthService($settingsRepository);
$lexosHubApiClient = new LexosHubApiClient($settingsRepository, $lexosAuthService);
$lexosDashboardService = new LexosDashboardService($lexosHubApiClient);
$lexosOrderWebhookRepository = new LexosOrderWebhookRepository($pdo);
$lexosOrderWebhookService = new LexosOrderWebhookService($lexosOrderWebhookRepository);
$mercadoLivreOrderMonitorService = new MercadoLivreOrderMonitorService($orderService);
$lexosOrderMonitorService = new LexosOrderMonitorService($lexosHubApiClient);
$protheusConnectionService = new ProtheusConnectionService($protheusSettingsRepository);
$protheusMedidosMonitorService = new ProtheusMedidosMonitorService($protheusConnectionService);
$protheusNfeMonitorService = new ProtheusNfeMonitorService($protheusConnectionService);
$protheusEdiConsultaService = new ProtheusEdiConsultaService($protheusConnectionService);
$protheusZa4PedidosErroMonitorService = new ProtheusZa4PedidosErroMonitorService($protheusConnectionService);

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
    'lexosAuthService' => $lexosAuthService,
    'mercadopagoSettingsRepository' => $mercadopagoSettingsRepository,
    'mercadopagoClient' => $mercadopagoClient,
    'mercadopagoPaymentService' => $mercadopagoPaymentService,
    'lexosDashboardService' => $lexosDashboardService,
    'lexosOrderWebhookService' => $lexosOrderWebhookService,
    'mercadoLivreOrderMonitorService' => $mercadoLivreOrderMonitorService,
    'lexosOrderMonitorService' => $lexosOrderMonitorService,
    'protheusSettingsRepository' => $protheusSettingsRepository,
    'protheusConnectionService' => $protheusConnectionService,
    'protheusMedidosMonitorService' => $protheusMedidosMonitorService,
    'protheusNfeMonitorService' => $protheusNfeMonitorService,
    'protheusEdiConsultaService' => $protheusEdiConsultaService,
    'protheusZa4PedidosErroMonitorService' => $protheusZa4PedidosErroMonitorService,
];
