<?php

declare(strict_types=1);

use App\Services\FindCepService;

/** @var FindCepService $findCep */
$findCep = $app['findCepService'];

$feedback = null;
$feedbackClass = 'ok';
$result = null;
$activeOp = trim((string) ($_POST['operation'] ?? $_GET['op'] ?? ''));
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
$resultBody = null;
if (is_array($result)) {
    $resultBody = $result['body'];
    if (is_string($resultBody)) {
        $resultPretty = $resultBody;
    } else {
        $resultPretty = json_encode($resultBody, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
}

$groupAnchor = static function (string $groupName): string {
    return preg_replace('/[^a-z0-9]+/i', '-', strtolower($groupName)) ?? 'g';
};

$fcIsScalar = static function (mixed $value): bool {
    return $value === null || is_scalar($value);
};

$fcFormatCell = static function (mixed $value, string $key = ''): string {
    if ($value === null) {
        return '—';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return number_format((float) $value, is_float($value) && fmod((float) $value, 1.0) !== 0.0 ? 2 : 0, ',', '.');
    }
    if (is_string($value)) {
        $numericKeys = ['requests', 'total', 'count', 'size', 'distance', 'distancia', 'score', 'qtd', 'quantidade'];
        $trimmed = trim($value);
        if (
            $key !== ''
            && in_array(strtolower($key), $numericKeys, true)
            && $trimmed !== ''
            && preg_match('/^-?\d+(\.\d+)?$/', $trimmed) === 1
        ) {
            $decimals = str_contains($trimmed, '.') ? 2 : 0;
            return number_format((float) $trimmed, $decimals, ',', '.');
        }
        return $value;
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
};

$fcColumnLabel = static function (string $key): string {
    static $labels = [
        'client' => 'Cliente',
        'requests' => 'Requisições',
        'year' => 'Ano',
        'month' => 'Mês',
        'cep' => 'CEP',
        'logradouro' => 'Logradouro',
        'bairro' => 'Bairro',
        'cidade' => 'Cidade',
        'localidade' => 'Localidade',
        'uf' => 'UF',
        'estado' => 'Estado',
        'codigo_ibge' => 'Código IBGE',
        'ibge' => 'IBGE',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'lat' => 'Latitude',
        'lon' => 'Longitude',
        'lng' => 'Longitude',
        'distance' => 'Distância',
        'distancia' => 'Distância',
        'unit' => 'Unidade',
        'unidade' => 'Unidade',
        'from' => 'De',
        'to' => 'Até',
        'inicio' => 'Início',
        'fim' => 'Fim',
        'faixa' => 'Faixa',
        'status' => 'Status',
        'total' => 'Total',
        'count' => 'Qtd.',
        'size' => 'Tamanho',
        'score' => 'Score',
        'name' => 'Nome',
        'nome' => 'Nome',
        'type' => 'Tipo',
        'tipo' => 'Tipo',
        'id' => 'ID',
        'fid' => 'FID',
        'client_id' => 'Client ID',
    ];
    if (isset($labels[$key])) {
        return $labels[$key];
    }
    $pretty = str_replace(['_', '-'], ' ', $key);
    return mb_convert_case($pretty, MB_CASE_TITLE, 'UTF-8');
};

$fcPreferredColumns = ['year', 'month', 'client', 'requests', 'cep', 'logradouro', 'bairro', 'cidade', 'localidade', 'uf', 'estado', 'codigo_ibge', 'ibge', 'latitude', 'longitude', 'lat', 'lon', 'lng', 'distance', 'distancia'];

$fcIsObjectRow = static function (mixed $value) use ($fcIsScalar): bool {
    if (!is_array($value) || $value === [] || array_is_list($value)) {
        return false;
    }
    foreach ($value as $v) {
        if (!$fcIsScalar($v)) {
            return false;
        }
    }
    return true;
};

$fcIsRowList = static function (mixed $value) use ($fcIsScalar): bool {
    if (!is_array($value) || $value === [] || !array_is_list($value)) {
        return false;
    }
    $hasScalarCol = false;
    foreach ($value as $row) {
        if (!is_array($row) || array_is_list($row) || $row === []) {
            return false;
        }
        foreach ($row as $v) {
            if ($fcIsScalar($v)) {
                $hasScalarCol = true;
            }
        }
    }
    return $hasScalarCol;
};

$fcCollectColumns = static function (array $rows) use ($fcPreferredColumns, $fcIsScalar): array {
    $keys = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach ($row as $k => $v) {
            if (!$fcIsScalar($v)) {
                continue;
            }
            $keys[(string) $k] = true;
        }
    }
    $cols = array_keys($keys);
    usort($cols, static function (string $a, string $b) use ($fcPreferredColumns): int {
        $ia = array_search($a, $fcPreferredColumns, true);
        $ib = array_search($b, $fcPreferredColumns, true);
        $ia = $ia === false ? 1000 : $ia;
        $ib = $ib === false ? 1000 : $ib;
        if ($ia !== $ib) {
            return $ia <=> $ib;
        }
        return strcasecmp($a, $b);
    });
    return $cols;
};

$resultTables = [];
$resultScalars = [];
if (is_array($resultBody)) {
    if ($fcIsRowList($resultBody)) {
        $cols = $fcCollectColumns($resultBody);
        if ($cols !== []) {
            $resultTables[] = [
                'title' => '',
                'columns' => $cols,
                'rows' => $resultBody,
            ];
        }
    } elseif (!array_is_list($resultBody)) {
        foreach ($resultBody as $k => $v) {
            if ($fcIsScalar($v)) {
                $resultScalars[(string) $k] = $v;
            } elseif ($fcIsRowList($v)) {
                $cols = $fcCollectColumns($v);
                if ($cols !== []) {
                    $resultTables[] = [
                        'title' => (string) $k,
                        'columns' => $cols,
                        'rows' => $v,
                    ];
                }
            } elseif ($fcIsObjectProps($v)) {
                foreach ($v as $sk => $sv) {
                    $resultScalars[(string) $k . '.' . (string) $sk] = $sv;
                }
            }
        }
    }
}
$hasStructuredView = $resultTables !== [] || $resultScalars !== [];

$resultGroupName = null;
$resultGroupAnchor = null;
$resultOpSummary = '';
foreach ($catalog as $item) {
    if ($item['id'] === $activeOp) {
        $resultGroupName = $item['group'];
        $resultGroupAnchor = $groupAnchor($item['group']);
        $resultOpSummary = (string) ($item['summary'] ?? '');
        break;
    }
}

$openResultModal = is_array($result);
$hasSettings = is_array($settings) && trim($clientId) !== '';
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
        display: flex;
        flex-direction: column;
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
    .fc-op button { margin-top: auto; padding-top: 10px; width: 100%; }
    .fc-op form { display: flex; flex-direction: column; flex: 1; gap: 0; }
    .fc-config-note {
        margin: 0 0 10px;
        padding: 8px 10px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        font-size: .8rem;
        color: #475569;
        line-height: 1.4;
    }
    .fc-config-note code {
        font-size: .78rem;
        background: #e2e8f0;
        padding: 1px 5px;
        border-radius: 4px;
    }

    .fc-modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 5000;
        background: rgba(15, 23, 42, .55);
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .fc-modal-backdrop.is-open { display: flex; }
    .fc-modal {
        background: #fff;
        border-radius: 14px;
        width: min(920px, 100%);
        max-height: min(88vh, 900px);
        box-shadow: 0 20px 50px rgba(0, 0, 0, .28);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .fc-modal-loading {
        width: min(340px, 100%);
        padding: 28px 24px;
        text-align: center;
    }
    .fc-modal-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 18px;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .fc-modal-head h3 {
        margin: 0 0 4px;
        font-size: 1.05rem;
    }
    .fc-modal-sub {
        margin: 0;
        font-size: .8rem;
        color: #64748b;
        word-break: break-all;
    }
    .fc-modal-close {
        border: 0;
        background: #111;
        color: #f5b700;
        width: 36px;
        height: 36px;
        border-radius: 8px;
        font-size: 1.2rem;
        cursor: pointer;
        flex-shrink: 0;
    }
    .fc-modal-body {
        padding: 14px 18px 18px;
        overflow: auto;
    }
    .fc-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: .78rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 999px;
        margin-bottom: 12px;
    }
    .fc-status.ok { background: #dcfce7; color: #166534; }
    .fc-status.err { background: #fee2e2; color: #991b1b; }
    .fc-kv {
        display: grid;
        grid-template-columns: minmax(120px, 200px) 1fr;
        gap: 8px 12px;
        margin-bottom: 14px;
    }
    .fc-kv dt {
        font-size: .78rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    .fc-kv dd {
        margin: 0;
        font-size: .92rem;
        color: #0f172a;
        word-break: break-word;
    }
    .fc-table-wrap {
        margin: 0 0 14px;
        overflow: auto;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        max-height: 52vh;
    }
    .fc-table-title {
        margin: 0 0 8px;
        font-size: .85rem;
        font-weight: 700;
        color: #334155;
    }
    .fc-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .88rem;
        min-width: 420px;
    }
    .fc-table th,
    .fc-table td {
        padding: 10px 12px;
        text-align: left;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
        white-space: nowrap;
    }
    .fc-table th {
        position: sticky;
        top: 0;
        background: #f8fafc;
        color: #475569;
        font-size: .75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
        z-index: 1;
    }
    .fc-table tbody tr:nth-child(even) { background: #f8fafc; }
    .fc-table tbody tr:hover { background: #fef9c3; }
    .fc-table td.fc-num { text-align: right; font-variant-numeric: tabular-nums; }
    .fc-table-empty {
        margin: 0 0 14px;
        color: #64748b;
        font-size: .9rem;
    }
    .fc-json-details {
        margin-top: 4px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }
    .fc-json-details > summary {
        cursor: pointer;
        padding: 10px 12px;
        background: #f8fafc;
        font-size: .82rem;
        font-weight: 600;
        color: #475569;
        user-select: none;
    }
    .fc-json-details[open] > summary { border-bottom: 1px solid #e2e8f0; }
    .fc-json {
        margin: 0;
        background: #0f172a;
        color: #e2e8f0;
        border-radius: 0;
        padding: 14px;
        overflow: auto;
        max-height: 40vh;
        font-size: .82rem;
        line-height: 1.45;
        white-space: pre-wrap;
        word-break: break-word;
    }
    .fc-json.is-primary {
        border-radius: 10px;
    }
    .fc-spinner {
        width: 42px;
        height: 42px;
        margin: 0 auto 14px;
        border: 3px solid #e2e8f0;
        border-top-color: #f5b700;
        border-radius: 50%;
        animation: fc-spin .8s linear infinite;
    }
    @keyframes fc-spin { to { transform: rotate(360deg); } }
    @media (max-width: 800px) {
        .fc-grid { grid-template-columns: 1fr; }
        .fc-kv { grid-template-columns: 1fr; gap: 2px 0; }
    }
</style>

<section class="card fc-page">
    <h1>Integração com Find CEP</h1>
    <p class="fc-lead">
        Configuração e consultas da API FindCEP (CEP, Endereço, Geolocalização, Localidades, Faixa de CEP, Rotas e Consumo).
        Documentação:
        <a href="https://www.findcep.com/docs/index.html" target="_blank" rel="noopener">OpenAPI / Swagger</a>.
    </p>

    <?php if ($feedback !== null && !$openResultModal): ?>
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

    <form method="post" action="<?= htmlspecialchars($pageUrl) ?>#fc-api-cep" class="fc-call-form" style="margin-top:8px;">
        <input type="hidden" name="form_type" value="findcep_test">
        <button type="submit"<?= $hasSettings ? '' : ' disabled' ?>>Testar conexão (GET /v1/cep/01001000.json)</button>
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
                $fields = $op['fields'] ?? [];
                $usesConfig = !empty($op['uses_config']) || $fields === [];
                $configNote = trim((string) ($op['config_note'] ?? ''));
                if ($configNote === '' && $fields === []) {
                    $configNote = 'Sem parâmetros extras — usa apenas a configuração salva (Referer / endpoint).';
                }
                if (!empty($op['uses_config'])) {
                    $configNote = $configNote !== ''
                        ? $configNote
                        : 'Usa automaticamente os dados salvos na configuração.';
                    if ($clientId !== '' || $fid !== '') {
                        $configNote .= ' Client ID: <code>' . htmlspecialchars($clientId !== '' ? $clientId : '—') . '</code>'
                            . ' · FID: <code>' . htmlspecialchars($fid !== '' ? $fid : '—') . '</code>';
                    }
                }
                ?>
                <div class="fc-op<?= $isActive ? ' active' : '' ?><?= !empty($op['featured']) ? ' active' : '' ?>">
                    <span class="fc-method"><?= htmlspecialchars($op['method']) ?></span>
                    <h3><?= htmlspecialchars($op['summary']) ?></h3>
                    <p class="fc-path"><?= htmlspecialchars($op['path']) ?></p>
                    <form method="post" action="<?= htmlspecialchars($pageUrl) ?>#fc-<?= htmlspecialchars($anchor) ?>" class="fc-call-form">
                        <input type="hidden" name="form_type" value="findcep_call">
                        <input type="hidden" name="operation" value="<?= htmlspecialchars($op['id']) ?>">
                        <?php if ($usesConfig && $fields === []): ?>
                            <p class="fc-config-note">
                                <?php if (!empty($op['uses_config'])): ?>
                                    <?= $configNote ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($configNote) ?>
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <?php foreach ($fields as $field): ?>
                                <?php
                                if (!empty($field['from_config'])) {
                                    continue;
                                }
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
                        <?php endif; ?>
                        <button type="submit"<?= $hasSettings ? '' : ' disabled' ?>>Consultar</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<div class="fc-modal-backdrop" id="fc-loading" aria-hidden="true">
    <div class="fc-modal fc-modal-loading">
        <div class="fc-spinner" aria-hidden="true"></div>
        <h3 style="margin:0 0 6px;">Consultando FindCEP…</h3>
        <p style="margin:0;color:#64748b;font-size:.9rem;">Aguarde — a página permanece nesta tela enquanto a API responde.</p>
    </div>
</div>

<div class="fc-modal-backdrop<?= $openResultModal ? ' is-open' : '' ?>" id="fc-result-modal" aria-hidden="<?= $openResultModal ? 'false' : 'true' ?>">
    <div class="fc-modal" role="dialog" aria-modal="true" aria-labelledby="fc-result-title">
        <div class="fc-modal-head">
            <div>
                <h3 id="fc-result-title"><?= htmlspecialchars($resultOpSummary !== '' ? $resultOpSummary : 'Resultado da consulta') ?></h3>
                <p class="fc-modal-sub" id="fc-result-sub">
                    <?php if ($openResultModal && is_array($result)): ?>
                        <strong><?= htmlspecialchars((string) $result['method']) ?></strong>
                        <?= htmlspecialchars((string) $result['url']) ?>
                    <?php endif; ?>
                </p>
            </div>
            <button type="button" class="fc-modal-close" id="fc-result-close" aria-label="Fechar">&times;</button>
        </div>
        <div class="fc-modal-body" id="fc-result-body">
            <?php if ($openResultModal && is_array($result)): ?>
                <span class="fc-status <?= !empty($result['success']) ? 'ok' : 'err' ?>">
                    <?= !empty($result['success']) ? 'Sucesso' : 'Falha' ?>
                    · HTTP <?= (int) $result['http_code'] ?>
                </span>

                <?php if ($resultScalars !== []): ?>
                    <dl class="fc-kv">
                        <?php foreach ($resultScalars as $k => $v): ?>
                            <dt><?= htmlspecialchars($fcColumnLabel((string) $k)) ?></dt>
                            <dd><?= htmlspecialchars($fcFormatCell($v, (string) $k)) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>

                <?php foreach ($resultTables as $table): ?>
                    <?php if (($table['title'] ?? '') !== ''): ?>
                        <p class="fc-table-title"><?= htmlspecialchars($fcColumnLabel((string) $table['title'])) ?></p>
                    <?php endif; ?>
                    <?php if (($table['rows'] ?? []) === []): ?>
                        <p class="fc-table-empty">Nenhum registro retornado.</p>
                    <?php else: ?>
                        <div class="fc-table-wrap">
                            <table class="fc-table">
                                <thead>
                                    <tr>
                                        <?php foreach ($table['columns'] as $col): ?>
                                            <th><?= htmlspecialchars($fcColumnLabel((string) $col)) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($table['rows'] as $row): ?>
                                        <tr>
                                            <?php foreach ($table['columns'] as $col): ?>
                                                <?php
                                                $cell = is_array($row) ? ($row[$col] ?? null) : null;
                                                $numKeys = ['requests', 'total', 'count', 'size', 'distance', 'distancia', 'score', 'qtd', 'quantidade'];
                                                $isNum = is_int($cell) || is_float($cell)
                                                    || (
                                                        is_string($cell)
                                                        && in_array(strtolower((string) $col), $numKeys, true)
                                                        && preg_match('/^-?\d+(\.\d+)?$/', trim($cell)) === 1
                                                    );
                                                ?>
                                                <td<?= $isNum ? ' class="fc-num"' : '' ?>><?= htmlspecialchars($fcFormatCell($cell, (string) $col)) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($hasStructuredView): ?>
                    <details class="fc-json-details">
                        <summary>Ver JSON bruto</summary>
                        <pre class="fc-json"><?= htmlspecialchars($resultPretty !== '' ? $resultPretty : '(vazio)') ?></pre>
                    </details>
                <?php else: ?>
                    <pre class="fc-json is-primary"><?= htmlspecialchars($resultPretty !== '' ? $resultPretty : '(vazio)') ?></pre>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var loading = document.getElementById('fc-loading');
    var resultModal = document.getElementById('fc-result-modal');
    var resultTitle = document.getElementById('fc-result-title');
    var resultSub = document.getElementById('fc-result-sub');
    var resultBody = document.getElementById('fc-result-body');
    var closeBtn = document.getElementById('fc-result-close');
    var labels = {
        client: 'Cliente', requests: 'Requisições', year: 'Ano', month: 'Mês',
        cep: 'CEP', logradouro: 'Logradouro', bairro: 'Bairro', cidade: 'Cidade',
        localidade: 'Localidade', uf: 'UF', estado: 'Estado', codigo_ibge: 'Código IBGE',
        ibge: 'IBGE', latitude: 'Latitude', longitude: 'Longitude', lat: 'Latitude',
        lon: 'Longitude', lng: 'Longitude', distance: 'Distância', distancia: 'Distância',
        unit: 'Unidade', unidade: 'Unidade', from: 'De', to: 'Até', inicio: 'Início',
        fim: 'Fim', faixa: 'Faixa', status: 'Status', total: 'Total', count: 'Qtd.',
        size: 'Tamanho', score: 'Score', name: 'Nome', nome: 'Nome', type: 'Tipo',
        tipo: 'Tipo', id: 'ID', fid: 'FID', client_id: 'Client ID'
    };
    var preferredCols = ['year', 'month', 'client', 'requests', 'cep', 'logradouro', 'bairro', 'cidade', 'localidade', 'uf', 'estado', 'codigo_ibge', 'ibge', 'latitude', 'longitude', 'lat', 'lon', 'lng', 'distance', 'distancia'];
    var numKeys = { requests: 1, total: 1, count: 1, size: 1, distance: 1, distancia: 1, score: 1, qtd: 1, quantidade: 1 };

    function openLoading() {
        if (!loading) return;
        loading.classList.add('is-open');
        loading.setAttribute('aria-hidden', 'false');
    }

    function closeLoading() {
        if (!loading) return;
        loading.classList.remove('is-open');
        loading.setAttribute('aria-hidden', 'true');
    }

    function closeResult() {
        if (!resultModal) return;
        resultModal.classList.remove('is-open');
        resultModal.setAttribute('aria-hidden', 'true');
    }

    function openResult() {
        if (!resultModal) return;
        resultModal.classList.add('is-open');
        resultModal.setAttribute('aria-hidden', 'false');
    }

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function columnLabel(key) {
        if (labels[key]) return labels[key];
        return String(key).replace(/[_-]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    function isScalar(value) {
        return value === null || typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean';
    }

    function formatCell(value, key) {
        if (value === null || value === undefined) return '—';
        if (typeof value === 'boolean') return value ? 'true' : 'false';
        if (typeof value === 'number') {
            return new Intl.NumberFormat('pt-BR', {
                maximumFractionDigits: Number.isInteger(value) ? 0 : 2
            }).format(value);
        }
        if (typeof value === 'string') {
            var trimmed = value.trim();
            if (key && numKeys[String(key).toLowerCase()] && /^-?\d+(\.\d+)?$/.test(trimmed)) {
                return new Intl.NumberFormat('pt-BR', {
                    maximumFractionDigits: trimmed.indexOf('.') >= 0 ? 2 : 0
                }).format(Number(trimmed));
            }
            return value;
        }
        try {
            return JSON.stringify(value);
        } catch (e) {
            return String(value);
        }
    }

    function isObjectProps(value) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
        var keys = Object.keys(value);
        if (!keys.length) return false;
        return keys.every(function (k) { return isScalar(value[k]); });
    }

    function isRowList(value) {
        if (!Array.isArray(value) || !value.length) return false;
        var hasScalar = false;
        for (var i = 0; i < value.length; i++) {
            var row = value[i];
            if (!row || typeof row !== 'object' || Array.isArray(row) || !Object.keys(row).length) {
                return false;
            }
            Object.keys(row).forEach(function (k) {
                if (isScalar(row[k])) hasScalar = true;
            });
        }
        return hasScalar;
    }

    function collectColumns(rows) {
        var map = {};
        rows.forEach(function (row) {
            Object.keys(row || {}).forEach(function (k) {
                if (isScalar(row[k])) map[k] = true;
            });
        });
        return Object.keys(map).sort(function (a, b) {
            var ia = preferredCols.indexOf(a);
            var ib = preferredCols.indexOf(b);
            if (ia < 0) ia = 1000;
            if (ib < 0) ib = 1000;
            if (ia !== ib) return ia - ib;
            return a.localeCompare(b);
        });
    }

    function buildStructured(body) {
        var scalars = {};
        var tables = [];
        if (isRowList(body)) {
            var cols = collectColumns(body);
            if (cols.length) tables.push({ title: '', columns: cols, rows: body });
        } else if (body && typeof body === 'object' && !Array.isArray(body)) {
            Object.keys(body).forEach(function (k) {
                var v = body[k];
                if (isScalar(v)) {
                    scalars[k] = v;
                } else if (isRowList(v)) {
                    var c = collectColumns(v);
                    if (c.length) tables.push({ title: k, columns: c, rows: v });
                } else if (isObjectProps(v)) {
                    Object.keys(v).forEach(function (sk) {
                        scalars[k + '.' + sk] = v[sk];
                    });
                }
            });
        }
        return { scalars: scalars, tables: tables };
    }

    function renderTable(table) {
        if (!table.rows || !table.rows.length) {
            return '<p class="fc-table-empty">Nenhum registro retornado.</p>';
        }
        var html = '';
        if (table.title) {
            html += '<p class="fc-table-title">' + esc(columnLabel(table.title)) + '</p>';
        }
        html += '<div class="fc-table-wrap"><table class="fc-table"><thead><tr>';
        table.columns.forEach(function (col) {
            html += '<th>' + esc(columnLabel(col)) + '</th>';
        });
        html += '</tr></thead><tbody>';
        table.rows.forEach(function (row) {
            html += '<tr>';
            table.columns.forEach(function (col) {
                var cell = row ? row[col] : null;
                var isNum = typeof cell === 'number'
                    || (typeof cell === 'string' && numKeys[String(col).toLowerCase()] && /^-?\d+(\.\d+)?$/.test(cell.trim()));
                html += '<td' + (isNum ? ' class="fc-num"' : '') + '>' + esc(formatCell(cell, col)) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        return html;
    }

    function prettyJson(body) {
        if (typeof body === 'string') return body;
        try {
            return JSON.stringify(body, null, 2);
        } catch (e) {
            return String(body == null ? '' : body);
        }
    }

    function showResult(payload) {
        var result = payload.result || {};
        var body = result.body;
        var structured = buildStructured(body);
        var hasStructured = Object.keys(structured.scalars).length > 0 || structured.tables.length > 0;
        var pretty = prettyJson(body);

        if (resultTitle) {
            resultTitle.textContent = payload.summary || 'Resultado da consulta';
        }
        if (resultSub) {
            resultSub.innerHTML = '<strong>' + esc(result.method || '') + '</strong> ' + esc(result.url || '');
        }

        var html = '<span class="fc-status ' + (result.success ? 'ok' : 'err') + '">'
            + (result.success ? 'Sucesso' : 'Falha')
            + ' · HTTP ' + esc(result.http_code == null ? '' : result.http_code)
            + '</span>';

        if (Object.keys(structured.scalars).length) {
            html += '<dl class="fc-kv">';
            Object.keys(structured.scalars).forEach(function (k) {
                html += '<dt>' + esc(columnLabel(k)) + '</dt><dd>' + esc(formatCell(structured.scalars[k], k)) + '</dd>';
            });
            html += '</dl>';
        }

        structured.tables.forEach(function (table) {
            html += renderTable(table);
        });

        if (hasStructured) {
            html += '<details class="fc-json-details"><summary>Ver JSON bruto</summary>'
                + '<pre class="fc-json">' + esc(pretty || '(vazio)') + '</pre></details>';
        } else {
            html += '<pre class="fc-json is-primary">' + esc(pretty || '(vazio)') + '</pre>';
        }

        if (resultBody) resultBody.innerHTML = html;
        openResult();
    }

    function submitAjax(form) {
        openLoading();
        var data = new FormData(form);
        data.set('ajax', '1');
        fetch(form.getAttribute('action') || window.location.href, {
            method: 'POST',
            body: data,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).then(function (res) {
            return res.json().then(function (json) {
                return { okHttp: res.ok, json: json };
            }).catch(function () {
                throw new Error('Resposta inválida do servidor.');
            });
        }).then(function (pack) {
            closeLoading();
            if (!pack.json || pack.json.ok === false) {
                throw new Error((pack.json && pack.json.error) || 'Falha na consulta FindCEP.');
            }
            showResult(pack.json);
        }).catch(function (err) {
            closeLoading();
            if (resultTitle) resultTitle.textContent = 'Erro na consulta';
            if (resultSub) resultSub.textContent = '';
            if (resultBody) {
                resultBody.innerHTML = '<span class="fc-status err">Falha</span>'
                    + '<p class="fc-table-empty">' + esc(err && err.message ? err.message : 'Erro desconhecido') + '</p>';
            }
            openResult();
        });
    }

    document.querySelectorAll('form.fc-call-form').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            submitAjax(form);
        });
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', closeResult);
    }
    if (resultModal) {
        resultModal.addEventListener('click', function (ev) {
            if (ev.target === resultModal) {
                closeResult();
            }
        });
    }
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') {
            closeResult();
            closeLoading();
        }
    });
})();
</script>
