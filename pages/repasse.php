<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$downloadFile = null;
$summary = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $result = $app['repasseService']->processUploadedFile($_FILES['repasse_file'] ?? []);
        $downloadFile = $result['file_name'] ?? null;
        $summary = $result;
        $feedback = 'Arquivo processado com sucesso. Clique no botão para baixar o resultado.';
    } catch (Throwable $exception) {
        $feedback = 'Erro ao processar arquivo: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}
?>
<style>
    .repasse-modal-overlay {
        display: none;
        position: fixed;
        z-index: 2000;
        inset: 0;
        background: rgba(0, 0, 0, .5);
        align-items: center;
        justify-content: center;
        padding: 16px;
        box-sizing: border-box;
    }
    .repasse-modal-overlay.is-open { display: flex; }
    .repasse-modal {
        background: #fff;
        border-radius: 8px;
        max-width: 920px;
        width: 100%;
        max-height: 88vh;
        display: flex;
        flex-direction: column;
        box-shadow: 0 10px 40px rgba(0, 0, 0, .2);
    }
    .repasse-modal header {
        padding: 12px 16px;
        border-bottom: 1px solid #e8edf5;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-shrink: 0;
        gap: 12px;
    }
    .repasse-modal header h3 { margin: 0; font-size: 1rem; }
    .repasse-modal pre {
        margin: 0;
        padding: 14px 16px;
        overflow: auto;
        flex: 1;
        min-height: 120px;
        font-size: 12px;
        line-height: 1.45;
        background: #1e1e1e;
        color: #d4d4d4;
        white-space: pre-wrap;
        word-break: break-word;
    }
    button.btn-repasse-json {
        margin-top: 0;
        padding: 6px 10px;
        font-size: 0.8rem;
        background: #eef2f8;
        color: #1a5084;
    }
    button.btn-repasse-json:hover { background: #e2e9f4; }
    .repasse-muted { color: #888; font-size: 0.85rem; }
</style>
<section class="card">
    <h1>Repasse</h1>
    <p>Faça upload de um arquivo XLS, XLSX ou CSV. O sistema lê a <strong>coluna D</strong> (<em>Operação relacionada</em>), ignora a linha 1 e consulta o Mercado Livre com <strong>esse valor como filtro de busca</strong> (<code>/orders/search</code> com parâmetro <code>q</code> e o seu <code>seller_id</code>). No JSON retornado, identifica o pedido pelo <code>id</code> ou pelo <code>id</code> em <code>payments</code>. Se o valor for numérico, tenta antes <code>GET /orders/{id}</code> direto.</p>

    <?php if ($feedback): ?>
        <div class="msg <?= $feedbackClass ?>"><?= htmlspecialchars($feedback) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <label>Arquivo</label>
        <input type="file" name="repasse_file" accept=".xls,.xlsx,.csv,.XLS,.XLSX,.CSV" required>
        <button type="submit">Processar Arquivo</button>
    </form>

    <?php if ($downloadFile): ?>
        <?php if ($summary): ?>
            <p>
                Processadas: <strong><?= htmlspecialchars((string) ($summary['processed'] ?? 0)) ?></strong> |
                Encontradas: <strong><?= htmlspecialchars((string) ($summary['found'] ?? 0)) ?></strong> |
                Não encontradas: <strong><?= htmlspecialchars((string) ($summary['not_found'] ?? 0)) ?></strong> |
                Erros: <strong><?= htmlspecialchars((string) ($summary['errors'] ?? 0)) ?></strong>
            </p>
        <?php endif; ?>
        <p>
            <a href="<?= htmlspecialchars(portal_wct_public_path($baseUrl, 'index.php?page=repasse&download=' . urlencode($downloadFile))) ?>">
                Baixar planilha com número do pedido
            </a>
        </p>
    <?php endif; ?>

    <?php if (!empty($summary['preview']) && is_array($summary['preview'])): ?>
        <h2>Prévia das informações lidas</h2>
        <table>
            <thead>
            <tr>
                <th>Linha</th>
                <th>Operação relacionada (coluna D)</th>
                <th>Número pedido ML</th>
                <th>ID pagamento (<code>payments.id</code>)</th>
                <th>Status</th>
                <th>Resposta API</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($summary['preview'] as $item): ?>
                <?php
                $traceB64 = (string) ($item['api_trace_b64'] ?? '');
                ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($item['linha'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($item['operacao_relacionada'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($item['numero_pedido_ml'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($item['id_pagamento_payments'] ?? '')) ?></td>
                    <td><?= htmlspecialchars((string) ($item['status_consulta'] ?? '')) ?></td>
                    <td>
                        <?php if ($traceB64 !== ''): ?>
                            <button type="button" class="btn-repasse-json" data-trace-b64="<?= htmlspecialchars($traceB64, ENT_QUOTES, 'UTF-8') ?>">Ver JSON</button>
                        <?php else: ?>
                            <span class="repasse-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div id="repasse-json-modal" class="repasse-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="repasse-json-modal-title" hidden>
            <div class="repasse-modal">
                <header>
                    <h3 id="repasse-json-modal-title">Respostas consultadas na API</h3>
                    <button type="button" id="repasse-json-modal-close" style="margin-top:0;padding:8px 14px;background:#3483fa;">Fechar</button>
                </header>
                <pre id="repasse-json-modal-pre"></pre>
            </div>
        </div>
        <script>
        (function () {
            var overlay = document.getElementById('repasse-json-modal');
            var pre = document.getElementById('repasse-json-modal-pre');
            var btnClose = document.getElementById('repasse-json-modal-close');
            if (!overlay || !pre) return;

            function openModal(text) {
                pre.textContent = text;
                overlay.hidden = false;
                overlay.classList.add('is-open');
                document.body.style.overflow = 'hidden';
            }
            function closeModal() {
                overlay.classList.remove('is-open');
                overlay.hidden = true;
                pre.textContent = '';
                document.body.style.overflow = '';
            }

            document.querySelectorAll('.btn-repasse-json').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var b64 = btn.getAttribute('data-trace-b64') || '';
                    var text = 'Não foi possível decodificar o JSON.';
                    try {
                        var raw = atob(b64);
                        var data = JSON.parse(raw);
                        text = JSON.stringify(data, null, 2);
                    } catch (e) {
                        text = 'Erro ao decodificar: ' + (e && e.message ? e.message : e);
                    }
                    openModal(text);
                });
            });

            if (btnClose) btnClose.addEventListener('click', closeModal);
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) closeModal();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.classList.contains('is-open')) closeModal();
            });
        })();
        </script>
    <?php endif; ?>
</section>
