<?php

declare(strict_types=1);

use App\Core\Database;
use App\Repositories\MercadoPagoSettingsRepository;
use App\Repositories\MessageLogRepository;
use App\Repositories\MessageTemplateRepository;
use App\Repositories\RequestLogRepository;
use App\Repositories\LexosOrderWebhookRepository;
use App\Repositories\RepasseMpJobRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\TokenRepository;
use App\Services\MercadoPagoClient;
use App\Services\MercadoPagoPaymentService;
use App\Services\MercadoLivreClient;
use App\Services\LexosDashboardService;
use App\Services\LexosAuthService;
use App\Services\LexosHubApiClient;
use App\Services\LexosOrderMonitorService;
use App\Services\LexosOrderWebhookService;
use App\Services\MessageService;
use App\Services\MlAdsReportService;
use App\Services\OrderService;
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
$client = new MercadoLivreClient($requestLogRepository);
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
$lexosOrderMonitorService = new LexosOrderMonitorService($lexosHubApiClient);

return [
    'config' => $config,
    'mercadoLivreClient' => $client,
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
    'lexosOrderMonitorService' => $lexosOrderMonitorService,
];
