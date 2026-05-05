<?php

declare(strict_types=1);

$app = require __DIR__ . '/app.php';
$baseUrl = $app['config']['app']['base_url'];
$trackingWctUrl = $app['config']['app']['tracking_wct_url'] ?? 'http://localhost:3001/admin/dashboard';
$page = $_GET['page'] ?? 'dashboard';
$allowedPages = ['dashboard', 'api-config', 'orders', 'repasse-mp', 'message-template', 'manual-send'];

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
        ['id' => 'api-config', 'label' => 'Configuração Repasse'],
        ['id' => 'orders', 'label' => 'Pedidos'],
        ['id' => 'message-template', 'label' => 'Mensageria ML'],
    ],
    'Mercado Pago' => [
        ['id' => 'repasse-mp', 'label' => 'Repasse MP'],
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
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal WCT</title>
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
<body>
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
                    $itemHref = $isExternal ? (string) ($item['href'] ?? '#') : ($baseUrl . '/index.php?page=' . urlencode($item['id']));
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
                        <a href="<?= htmlspecialchars($baseUrl) ?>/index.php?page=message-template" class="<?= $page === 'message-template' ? 'active' : '' ?>">Mensagem</a>
                        <a href="<?= htmlspecialchars($baseUrl) ?>/index.php?page=manual-send" class="<?= $page === 'manual-send' ? 'active' : '' ?>">Mensagem manual</a>
                    </nav>
                </div>
            <?php endif; ?>
            <?php require __DIR__ . '/pages/' . $page . '.php'; ?>
        </div>
    </main>
</div>
</body>
</html>
