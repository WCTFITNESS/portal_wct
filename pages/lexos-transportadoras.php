<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$listError = null;
$registered = [];
$registerResult = null;

$lexosCredStatus = $app['lexosCredentialsService']->getStatusSummary();
$hasLexosToken = $lexosCredStatus['has_token'];
$hasLexosIntegrationKey = $lexosCredStatus['has_key'];
$lexosApiReady = $app['lexosCredentialsService']->isReady();
$lexosCredSource = (string) $lexosCredStatus['source'];
$trackingCred = $lexosCredStatus['tracking'];

/** @var \App\Services\LexosTransportadoraService $svc */
$svc = $app['lexosTransportadoraService'];

$form = [
    'codigo' => trim((string) ($_POST['codigo'] ?? $_GET['preset_codigo'] ?? '')),
    'razao_social' => trim((string) ($_POST['razao_social'] ?? $_GET['preset_razao'] ?? '')),
    'nome_fantasia' => trim((string) ($_POST['nome_fantasia'] ?? $_GET['preset_fantasia'] ?? '')),
    'cnpj' => trim((string) ($_POST['cnpj'] ?? '')),
    'telefone' => trim((string) ($_POST['telefone'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'site' => trim((string) ($_POST['site'] ?? $_GET['preset_site'] ?? '')),
];

if (isset($_GET['preset']) && $_GET['preset'] !== '') {
    $idx = (int) $_GET['preset'];
    if (isset(\App\Services\LexosTransportadoraService::PRESETS[$idx])) {
        $p = \App\Services\LexosTransportadoraService::PRESETS[$idx];
        $form['codigo'] = $p['codigo'];
        $form['razao_social'] = $p['razao'];
        $form['nome_fantasia'] = $p['fantasia'];
        $form['site'] = $p['site'];
    }
}

if ($lexosApiReady) {
    try {
        $registered = $svc->summarizeRows($svc->listRegistered());
    } catch (Throwable $e) {
        $listError = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'lexos_transportadora_register') {
    if (!$lexosApiReady) {
        $feedback = 'Configure credenciais Lexos em Configuração API (aba Lexos): portal, banco do Tracking ou ambos.';
        $feedbackClass = 'err';
    } else {
        try {
            $registerResult = $svc->register([
                'codigo' => $form['codigo'],
                'razao_social' => $form['razao_social'],
                'nome_fantasia' => $form['nome_fantasia'],
                'cnpj' => $form['cnpj'],
                'telefone' => $form['telefone'],
                'email' => $form['email'],
                'site' => $form['site'],
            ]);
            $feedback = (string) $registerResult['message'];
            $feedbackClass = ($registerResult['success'] ?? false) ? 'ok' : 'err';
            if ($registerResult['success'] ?? false) {
                try {
                    $registered = $svc->summarizeRows($svc->listRegistered());
                } catch (Throwable) {
                }
            }
        } catch (Throwable $e) {
            $feedback = $e->getMessage();
            $feedbackClass = 'err';
        }
    }
}

$hubExpedicaoUrl = 'https://app-hub.lexos.com.br/#/expedicao/entrega/lista/?aba=Todos';

?>
<div class="card lexos-transportadoras-page">
    <h1>Transportadoras Lexos</h1>
    <p>
        Cadastro no tenant Lexos Hub. A API oficial não documenta criação de transportadora; esta tela tenta os endpoints do Hub
        e mostra o resultado. Se falhar, use o cadastro manual no Lexos (passo a passo abaixo).
    </p>

    <?php if ($feedback !== null): ?>
        <p class="feedback <?= htmlspecialchars($feedbackClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($feedback, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <section class="lexos-panel lexos-cred-status">
        <h2>Credenciais Lexos (obrigatórias para chamar a API)</h2>
        <ul class="cred-checklist">
            <li class="<?= $hasLexosToken ? 'ok' : 'missing' ?>">
                Token Lexos: <?= $hasLexosToken ? 'disponível' : 'ausente' ?>
                <?php if ($hasLexosToken): ?>(origem: <?= htmlspecialchars($lexosCredSource) ?>)<?php endif; ?>
            </li>
            <li class="<?= $hasLexosIntegrationKey ? 'ok' : 'missing' ?>">
                Chave de integração (header Chave): <?= $hasLexosIntegrationKey ? 'disponível' : 'ausente' ?>
            </li>
            <?php if ($trackingCred['connected'] ?? false): ?>
                <li class="<?= ($trackingCred['has_row'] ?? false) ? 'ok' : 'missing' ?>">
                    Banco Tracking: <?= ($trackingCred['has_row'] ?? false) ? 'lexos_tokens com registro' : 'conectado, sem registro' ?>
                </li>
            <?php endif; ?>
        </ul>
        <?php if (!$lexosApiReady): ?>
            <p class="feedback err">
                Falta <?= !$hasLexosToken && !$hasLexosIntegrationKey ? 'token e chave' : (!$hasLexosToken ? 'o token' : 'a chave de integração') ?>.
                <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=api-config&api_tab=lexos')) ?>">Configuração API → Lexos</a>:
                preencha no portal ou configure a URL do banco Tracking (mesmos dados do app Tracking).
            </p>
        <?php else: ?>
            <p class="feedback ok">Credenciais OK (origem: <?= htmlspecialchars($lexosCredSource) ?>) — preencha CNPJ e razão social para cadastrar.</p>
        <?php endif; ?>
    </section>

    <section class="lexos-panel">
        <h2>Modelos WCT (preencher formulário)</h2>
        <div class="preset-grid">
            <?php foreach (\App\Services\LexosTransportadoraService::PRESETS as $i => $preset): ?>
                <a class="preset-chip" href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=lexos-transportadoras&preset=' . $i)) ?>">
                    <strong><?= htmlspecialchars($preset['codigo'], ENT_QUOTES, 'UTF-8') ?></strong>
                    <?= htmlspecialchars($preset['fantasia'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="lexos-panel">
        <h2>Nova transportadora</h2>
        <form method="post" class="filter-grid lexos-form-grid">
            <input type="hidden" name="form_type" value="lexos_transportadora_register">
            <label>Código interno (ex. 000721)
                <input type="text" name="codigo" value="<?= htmlspecialchars($form['codigo'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label>Razão social *
                <input type="text" name="razao_social" value="<?= htmlspecialchars($form['razao_social'], ENT_QUOTES, 'UTF-8') ?>" required>
            </label>
            <label>Nome fantasia
                <input type="text" name="nome_fantasia" value="<?= htmlspecialchars($form['nome_fantasia'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label>CNPJ (14 dígitos) *
                <input type="text" name="cnpj" value="<?= htmlspecialchars($form['cnpj'], ENT_QUOTES, 'UTF-8') ?>" required maxlength="18" placeholder="00.000.000/0000-00">
            </label>
            <label>Telefone
                <input type="text" name="telefone" value="<?= htmlspecialchars($form['telefone'], ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label>E-mail
                <input type="text" name="email" value="<?= htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
            </label>
            <label>Site
                <input type="text" name="site" value="<?= htmlspecialchars($form['site'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://...">
            </label>
            <div class="form-actions">
                <p class="form-hint">Obrigatório: <strong>razão social</strong> e <strong>CNPJ com 14 dígitos</strong> (só números ou com máscara).</p>
                <button type="submit">Tentar cadastrar na Lexos</button>
            </div>
        </form>
    </section>

    <?php if (is_array($registerResult) && !empty($registerResult['attempts'])): ?>
        <section class="lexos-panel">
            <h2>Log das tentativas de API</h2>
            <table class="table">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>HTTP</th>
                        <th>OK</th>
                        <th>Resposta (trecho)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registerResult['attempts'] as $att): ?>
                        <tr>
                            <td><code><?= htmlspecialchars((string) ($att['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= (int) ($att['status'] ?? 0) ?></td>
                            <td><?= !empty($att['ok']) ? 'sim' : 'não' ?></td>
                            <td><pre class="lexos-pre"><?= htmlspecialchars(
                                is_string($att['body'] ?? null)
                                    ? substr((string) $att['body'], 0, 300)
                                    : substr(json_encode($att['body'] ?? '', JSON_UNESCAPED_UNICODE) ?: '', 0, 300),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <section class="lexos-panel">
        <h2>Cadastradas no Lexos (<?= count($registered) ?>)</h2>
        <?php if ($listError !== null): ?>
            <p class="feedback err"><?= htmlspecialchars($listError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php elseif ($registered === []): ?>
            <p class="text-muted">Nenhuma transportadora retornada pela API ou lista vazia.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Código</th>
                        <th>Nome</th>
                        <th>CNPJ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($registered as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['codigo'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['nome'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($row['cnpj'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="lexos-panel lexos-help">
        <h2>Cadastro manual no Lexos Hub (quando a API não cadastra)</h2>
        <ol>
            <li>Acesse o <a href="<?= htmlspecialchars($hubExpedicaoUrl) ?>" target="_blank" rel="noopener">Lexos Hub → Expedição</a> com usuário <strong>administrador</strong>.</li>
            <li>Menu <strong>Configurações</strong> ou <strong>Cadastros</strong> → <strong>Transportadoras</strong> (nome pode variar).</li>
            <li>Inclua CNPJ e razão social <strong>iguais à NF-e</strong> do pedido.</li>
            <li>Salve, atualize o checkout do pedido (F5) e selecione a transportadora na aba Logística.</li>
        </ol>
        <p>
            Para descobrir qual transportadora o pedido exige, use
            <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=lexos-diagnostico-expedicao')) ?>">Diagnóstico expedição</a>.
        </p>
        <p class="text-muted">
            Documentação Lexos API:
            <a href="https://portal.lexos.com.br/support/solutions/articles/9000225229-integracao-lexos-api" target="_blank" rel="noopener">Integração Lexos API</a>.
        </p>
    </section>
</div>

<style>
    .lexos-transportadoras-page .lexos-panel {
        margin: 1.25rem 0;
        padding: 1rem 1.25rem;
        border: 1px solid var(--border, #e5e7eb);
        border-radius: 8px;
        background: #fafafa;
    }
    .lexos-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 0.75rem 1rem;
    }
    .lexos-form-grid label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.875rem; }
    .form-actions { grid-column: 1 / -1; }
    .preset-grid { display: flex; flex-wrap: wrap; gap: 0.5rem; }
    .preset-chip {
        display: inline-flex;
        flex-direction: column;
        padding: 0.5rem 0.75rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        background: #fff;
        text-decoration: none;
        color: #334155;
        font-size: 0.8rem;
    }
    .preset-chip:hover { border-color: #2563eb; }
    .preset-chip strong { color: #1e40af; }
    .lexos-pre { font-size: 0.75rem; max-height: 80px; overflow: auto; margin: 0; white-space: pre-wrap; }
    .lexos-help ol { margin: 0.5rem 0 0 1.25rem; }
    .text-muted { color: #64748b; font-size: 0.875rem; }
    .cred-checklist { list-style: none; padding: 0; margin: 0.5rem 0; }
    .cred-checklist li { padding: 0.35rem 0; font-size: 0.9rem; }
    .cred-checklist li.ok { color: #047857; }
    .cred-checklist li.ok::before { content: '✓ '; }
    .cred-checklist li.missing { color: #b45309; font-weight: 600; }
    .cred-checklist li.missing::before { content: '⚠ '; }
    .form-hint { font-size: 0.85rem; color: #64748b; margin: 0 0 0.5rem 0; }
</style>
