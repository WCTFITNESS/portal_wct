<?php

declare(strict_types=1);

use App\Services\FindCepService;

/** @var FindCepService $findCep */
$findCep = $app['findCepService'];

$feedback = null;
$feedbackClass = 'ok';
$result = null;
$activeOp = trim((string) ($_POST['operation'] ?? $_GET['op'] ?? 'cep'));
$formValues = is_array($_POST['params'] ?? null) ? $_POST['params'] : [];

$settings = $findCep->getSettings();
$catalog = $findCep->catalog();
$groups = [];
foreach ($catalog as $item) {
    $groups[$item['group']][] = $item;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formType = (string) ($_POST['form_type'] ?? '');
    try {
        if ($formType === 'findcep_save') {
            $findCep->saveSettings([
                'scheme' => (string) ($_POST['scheme'] ?? 'https'),
                'client_id' => (string) ($_POST['client_id'] ?? ''),
                'client_url_hash' => (string) ($_POST['client_url_hash'] ?? ''),
                'fid' => (string) ($_POST['fid'] ?? ''),
                'referer' => (string) ($_POST['referer'] ?? ''),
                'authorization' => (string) ($_POST['authorization'] ?? ''),
                'custom_base_url' => (string) ($_POST['custom_base_url'] ?? ''),
                'timeout_seconds' => (string) ($_POST['timeout_seconds'] ?? '30'),
            ]);
            $settings = $findCep->getSettings();
            $feedback = 'Configuração FindCEP salva.';
        }

        if ($formType === 'findcep_test') {
            $result = $findCep->testConnection();
            $activeOp = 'cep';
            $feedback = $result['success']
                ? 'Teste OK — CEP 01001000 consultado.'
                : ('Teste falhou: ' . ($result['error'] ?: 'HTTP ' . $result['http_code']));
            $feedbackClass = $result['success'] ? 'ok' : 'err';
        }

        if ($formType === 'findcep_call') {
            $activeOp = trim((string) ($_POST['operation'] ?? ''));
            $params = is_array($_POST['params'] ?? null) ? $_POST['params'] : [];
            $formValues = $params;
            $result = $findCep->execute($activeOp, $params);
            $feedback = $result['success']
                ? ('Consulta OK — HTTP ' . $result['http_code'])
                : ('Consulta falhou: ' . ($result['error'] ?: 'HTTP ' . $result['http_code']));
            $feedbackClass = $result['success'] ? 'ok' : 'err';
        }
    } catch (Throwable $e) {
        $feedback = $e->getMessage();
        $feedbackClass = 'err';
    }
}

$scheme = (string) ($settings['scheme'] ?? 'https');
$clientId = (string) ($settings['client_id'] ?? '');
$clientUrlHash = (string) ($settings['client_url_hash'] ?? '');
$fid = (string) ($settings['fid'] ?? '');
$referer = (string) ($settings['referer'] ?? '');
$customBaseUrl = (string) ($settings['custom_base_url'] ?? '');
$timeoutSeconds = (string) ($settings['timeout_seconds'] ?? '30');
$baseUrlPreview = $findCep->resolveBaseUrl($settings ?? []);
$refererPreview = $findCep->resolveReferer($settings ?? []);
$pageUrl = portal_wct_public_path($baseUrl, 'index.php?page=find-cep');

$resultPretty = '';
if (is_array($result)) {
    $payload = $result['body'];
    if (is_string($payload)) {
        $resultPretty = $payload;
    } else {
        $resultPretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}

$groupAnchor = static function (string $groupName): string {
    return preg_replace('/[^a-z0-9]+/i', '-', strtolower($groupName)) ?? 'g';
};

$resultGroupName = null;
$resultGroupAnchor = null;
foreach ($catalog as $item) {
    if ($item['id'] === $activeOp) {
        $resultGroupName = $item['group'];
        $resultGroupAnchor = $groupAnchor($item['group']);
        break;
    }
}

$renderResultBlock = static function () use ($result, $resultPretty): void {
    if (!is_array($result)) {
        return;
    }
    ?>
    <div class="fc-result-block" id="fc-result">
        <h3>Resultado</h3>
        <p class="fc-meta">
            <strong><?= htmlspecialchars($result['method']) ?></strong>
            <?= htmlspecialchars($result['url']) ?>
            · HTTP <?= (int) $result['http_code'] ?>
            · Referer: <?= htmlspecialchars((string) $result['referer']) ?>
        </p>
        <pre class="fc-result"><?= htmlspecialchars($resultPretty !== '' ? $resultPretty : '(vazio)') ?></pre>
    </div>
    <?php
};
?>
<style>
    .fc-page h1 { margin-bottom: 6px; }
    .fc-lead { color: #64748b; margin: 0 0 16px; }
    .fc-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px 16px;
        max-width: 920px;
    }
    .fc-grid .full { grid-column: 1 / -1; }
    .fc-hint { font-size: .82rem; color: #64748b; margin: 4px 0 0; font-weight: normal; }
    .fc-preview {
        margin: 10px 0 0;
        padding: 10px 12px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: .85rem;
        color: #334155;
        word-break: break-all;
    }
    .fc-nav {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 0 0 16px;
    }
    .fc-nav a {
        text-decoration: none;
        font-size: .82rem;
        font-weight: 600;
        color: #334155;
        background: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 999px;
        padding: 6px 12px;
    }
    .fc-nav a:hover { border-color: #111; }
    .fc-nav a.fc-nav-featured {
        background: #111;
        color: #f5b700;
        border-color: #111;
    }
    .fc-group { margin-top: 18px; }
    .fc-group.fc-featured {
        border: 2px solid #111;
        box-shadow: 0 0 0 3px rgba(245, 183, 0, .35);
    }
    .fc-group.fc-featured h2 {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .fc-badge {
        display: inline-block;
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        padding: 3px 8px;
        border-radius: 999px;
        background: #f5b700;
        color: #111;
    }
    .fc-group h2 {
        margin: 0 0 10px;
        font-size: 1.05rem;
        border-bottom: 1px solid #e2e8f0;
        padding-bottom: 6px;
    }
    .fc-ops {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 12px;
    }
    .fc-op {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px;
        background: #fff;
    }
    .fc-op.active { border-color: #111; box-shadow: 0 0 0 1px #111; }
    .fc-op h3 { margin: 0 0 4px; font-size: .92rem; }
    .fc-path {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: .72rem;
        color: #64748b;
        margin: 0 0 10px;
        word-break: break-all;
    }
    .fc-method {
        display: inline-block;
        font-size: .68rem;
        font-weight: 700;
        letter-spacing: .04em;
        padding: 2px 6px;
        border-radius: 4px;
        background: #111;
        color: #f5b700;
        margin-bottom: 6px;
    }
    .fc-op label { display: block; margin-top: 8px; font-size: .82rem; }
    .fc-op input { width: 100%; box-sizing: border-box; }
    .fc-op button { margin-top: 10px; width: 100%; }
    .fc-result-block {
        margin-top: 16px;
        padding-top: 14px;
        border-top: 1px solid #e2e8f0;
    }
    .fc-result-block h3 {
        margin: 0 0 6px;
        font-size: .95rem;
    }
    .fc-result {
        margin-top: 8px;
        background: #0f172a;
        color: #e2e8f0;
        border-radius: 10px;
        padding: 14px;
        overflow: auto;
        max-height: 480px;
        font-size: .82rem;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .fc-meta { font-size: .85rem; color: #475569; margin: 8px 0; }
    @media (max-width: 800px) {
        .fc-grid { grid-template-columns: 1fr; }
    }
</style>

<section class="card fc-page">
    <h1>Integração com Find CEP</h1>
    <p class="fc-lead">
        Configuração e consultas da API FindCEP (CEP, Endereço, Geolocalização, Localidades, Faixa de CEP, Rotas e Consumo).
        Documentação:
        <a href="https://www.findcep.com/docs/index.html" target="_blank" rel="noopener">OpenAPI / Swagger</a>.
    </p>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <div class="fc-nav">
        <a href="#fc-config">Configuração</a>
        <?php foreach (array_keys($groups) as $groupName): ?>
            <?php
            $isFeaturedGroup = false;
            foreach ($groups[$groupName] as $opItem) {
                if (!empty($opItem['featured'])) {
                    $isFeaturedGroup = true;
                    break;
                }
            }
            ?>
            <a href="#fc-<?= htmlspecialchars($groupAnchor($groupName)) ?>"<?= $isFeaturedGroup ? ' class="fc-nav-featured"' : '' ?>><?= htmlspecialchars($groupName) ?></a>
        <?php endforeach; ?>
        <?php if ($result && $resultGroupAnchor): ?>
            <a href="#fc-<?= htmlspecialchars($resultGroupAnchor) ?>">Resultado</a>
        <?php endif; ?>
    </div>

    <h2 id="fc-config">Configuração</h2>
    <form method="post">
        <input type="hidden" name="form_type" value="findcep_save">
        <div class="fc-grid">
            <div>
                <label>Scheme</label>
                <select name="scheme">
                    <option value="https"<?= $scheme === 'https' ? ' selected' : '' ?>>https</option>
                    <option value="http"<?= $scheme === 'http' ? ' selected' : '' ?>>http</option>
                </select>
            </div>
            <div>
                <label>Timeout (s)</label>
                <input type="number" name="timeout_seconds" min="5" max="120" value="<?= htmlspecialchars($timeoutSeconds) ?>">
            </div>
            <div>
                <label>Client ID</label>
                <input type="text" name="client_id" value="<?= htmlspecialchars($clientId) ?>" placeholder="demo / trial / seu-id" autocomplete="off">
                <p class="fc-hint">Enviado por e-mail após a assinatura. Trial: <code>trial</code> ou <code>demo</code>.</p>
            </div>
            <div>
                <label>Client URL Hash</label>
                <input type="text" name="client_url_hash" value="<?= htmlspecialchars($clientUrlHash) ?>" placeholder="50f1e50fa2620ae7" autocomplete="off">
                <p class="fc-hint">Opcional. Se preenchido: <code>{id}-{hash}.api.findcep.com</code></p>
            </div>
            <div>
                <label>FID</label>
                <input type="text" name="fid" value="<?= htmlspecialchars($fid) ?>" placeholder="E1CVY42APYI5SC" autocomplete="off">
            </div>
            <div>
                <label>Referer</label>
                <input type="text" name="referer" value="<?= htmlspecialchars($referer) ?>" placeholder="FID.CLIENT_ID ou https://seu-site.com" autocomplete="off">
                <p class="fc-hint">Obrigatório. Vazio = monta <code>FID.CLIENT_ID</code>.</p>
            </div>
            <div class="full">
                <label>Base URL customizada (opcional)</label>
                <input type="text" name="custom_base_url" value="<?= htmlspecialchars($customBaseUrl) ?>" placeholder="https://seu-cliente.api.findcep.com" autocomplete="off">
                <p class="fc-hint">Se preenchida, ignora scheme/client_id/hash na montagem do host.</p>
            </div>
            <div class="full">
                <label>Authorization (API Routes)</label>
                <input type="password" name="authorization" value="" placeholder="<?= $settings && trim((string) ($settings['authorization'] ?? '')) !== '' ? 'Deixe em branco para manter a chave salva' : 'Chave fornecida pelo suporte FindCEP' ?>" autocomplete="new-password">
            </div>
        </div>

        <div class="fc-preview">
            <div><strong>Base URL efetiva:</strong> <?= htmlspecialchars($baseUrlPreview) ?></div>
            <div><strong>Referer efetivo:</strong> <?= htmlspecialchars($refererPreview) ?></div>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
            <button type="submit">Salvar configuração</button>
        </div>
    </form>

    <form method="post" action="<?= htmlspecialchars($pageUrl) ?>#fc-api-cep" style="margin-top:8px;">
        <input type="hidden" name="form_type" value="findcep_test">
        <button type="submit"<?= $settings ? '' : ' disabled' ?>>Testar conexão (GET /v1/cep/01001000.json)</button>
    </form>
</section>

<?php foreach ($groups as $groupName => $ops): ?>
    <?php
    $anchor = $groupAnchor($groupName);
    $isFeaturedGroup = false;
    foreach ($ops as $opItem) {
        if (!empty($opItem['featured'])) {
            $isFeaturedGroup = true;
            break;
        }
    }
    ?>
    <section class="card fc-group<?= $isFeaturedGroup ? ' fc-featured' : '' ?>" id="fc-<?= htmlspecialchars($anchor) ?>">
        <h2>
            <?= htmlspecialchars($groupName) ?>
            <?php if ($isFeaturedGroup): ?>
                <span class="fc-badge">Monitor de consumo</span>
            <?php endif; ?>
        </h2>
        <div class="fc-ops">
            <?php foreach ($ops as $op): ?>
                <?php
                $isActive = $activeOp === $op['id'];
                $opValues = $isActive ? $formValues : [];
                ?>
                <div class="fc-op<?= $isActive ? ' active' : '' ?><?= !empty($op['featured']) ? ' active' : '' ?>">
                    <span class="fc-method"><?= htmlspecialchars($op['method']) ?></span>
                    <h3><?= htmlspecialchars($op['summary']) ?></h3>
                    <p class="fc-path"><?= htmlspecialchars($op['path']) ?></p>
                    <form method="post" action="<?= htmlspecialchars($pageUrl) ?>#fc-<?= htmlspecialchars($anchor) ?>">
                        <input type="hidden" name="form_type" value="findcep_call">
                        <input type="hidden" name="operation" value="<?= htmlspecialchars($op['id']) ?>">
                        <?php foreach ($op['fields'] as $field): ?>
                            <?php
                            $fname = $field['name'];
                            $ftype = $field['type'] ?? 'text';
                            $fval = (string) ($opValues[$fname] ?? '');
                            ?>
                            <label>
                                <?= htmlspecialchars($field['label']) ?>
                                <?php if (!empty($field['required'])): ?>*<?php endif; ?>
                            </label>
                            <input
                                type="<?= htmlspecialchars($ftype) ?>"
                                name="params[<?= htmlspecialchars($fname) ?>]"
                                value="<?= htmlspecialchars($fval) ?>"
                                placeholder="<?= htmlspecialchars((string) ($field['placeholder'] ?? '')) ?>"
                                <?= !empty($field['required']) ? 'required' : '' ?>
                                autocomplete="off"
                            >
                            <?php if (!empty($field['hint'])): ?>
                                <p class="fc-hint"><?= htmlspecialchars($field['hint']) ?></p>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <button type="submit"<?= $settings ? '' : ' disabled' ?>>Consultar</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (is_array($result) && $resultGroupName === $groupName): ?>
            <?php $renderResultBlock(); ?>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
