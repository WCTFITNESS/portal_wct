<?php

declare(strict_types=1);

$app = require __DIR__ . '/app.php';
$baseUrl = $app['config']['app']['base_url'];
$trackingWctUrl = $app['config']['app']['tracking_wct_url'] ?? 'http://localhost:3001/admin/dashboard';
$page = $_GET['page'] ?? 'dashboard';
$allowedPages = [
    'dashboard',
    'api-config',
    'orders',
    'monitor-pedidos',
    'repasse-mp',
    'message-template',
    'manual-send',
    'ml-ads-report',
    'protheus-config',
    'protheus-monitor-medidos',
    'protheus-monitor-nfe',
    'protheus-consulta-edi',
];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
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
        ['id' => 'dashboard', 'label' => 'Dashboard'],
        ['id' => 'api-config', 'label' => 'Configuração API'],
        ['id' => 'orders', 'label' => 'Pedidos'],
        ['id' => 'monitor-pedidos', 'label' => 'Monitor de Pedidos'],
        ['id' => 'message-template', 'label' => 'Mensageria ML'],
    ],
    'Mercado Pago' => [
        ['id' => 'repasse-mp', 'label' => 'Repasse MP'],
    ],
    'Relatórios ML' => [
        ['id' => 'ml-ads-report', 'label' => 'Relatório de anúncios'],
    ],
    'Protheus' => [
        ['id' => 'protheus-config', 'label' => 'Config Protheus'],
        ['id' => 'protheus-monitor-medidos', 'label' => 'Monitor de Medidos'],
        ['id' => 'protheus-monitor-nfe', 'label' => 'Monitor NF-e SEFAZ'],
        ['id' => 'protheus-consulta-edi', 'label' => 'Consultas EDI'],
    ],
    'Integração' => [
        [
            'id' => 'tracking-wct',
            'label' => 'Tracking WCT',
            'external' => true,
            'href' => $trackingWctUrl,
        ],
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
        if ($emissaoDe === '') {
            $emissaoDe = $dataCorte;
        }
        if ($emissaoDe < $dataCorte) {
            $emissaoDe = $dataCorte;
        }

        $filePath = $app['protheusNfeMonitorService']->exportToXlsx($filial, $emissaoDe, $emissaoAte, $statusFilter);
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
            trim((string) ($_GET['transportadora'] ?? '')),
            trim((string) ($_GET['sit_edi'] ?? '')),
            trim((string) ($_GET['arquivo'] ?? ''))
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

if ($page === 'protheus-monitor-medidos' && ($_GET['export'] ?? '') === 'xlsx') {
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

        $filePath = $app['protheusMedidosMonitorService']->exportToXlsx($filial, $emissaoDe, $emissaoAte);
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
    </style>
</head>
<body class="<?= in_array($page, ['protheus-monitor-medidos', 'protheus-monitor-nfe', 'protheus-consulta-edi'], true) ? 'page-protheus-monitor-full' : '' ?>">
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
            <?php if (in_array($page, ['protheus-config', 'protheus-monitor-medidos', 'protheus-monitor-nfe', 'protheus-consulta-edi'], true)): ?>
                <div class="subnav-sticky">
                    <nav class="subnav">
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-config')) ?>" class="<?= $page === 'protheus-config' ? 'active' : '' ?>">Config Protheus</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-monitor-medidos')) ?>" class="<?= $page === 'protheus-monitor-medidos' ? 'active' : '' ?>">Monitor de Medidos</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-monitor-nfe')) ?>" class="<?= $page === 'protheus-monitor-nfe' ? 'active' : '' ?>">Monitor NF-e SEFAZ</a>
                        <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=protheus-consulta-edi')) ?>" class="<?= $page === 'protheus-consulta-edi' ? 'active' : '' ?>">Consultas EDI</a>
                    </nav>
                </div>
            <?php endif; ?>
            <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
        </div>
    </main>
</div>
</body>
</html>
