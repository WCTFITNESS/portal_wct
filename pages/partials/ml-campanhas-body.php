<?php

declare(strict_types=1);

/**
 * Partial compartilhado — campanhas ML.
 *
 * Variaveis esperadas:
 * - $mlCampanhasPageId (string)
 * - $mlCampanhasItemStatus (candidate|pending|started)
 * - $mlCampanhasTitle (string)
 * - $mlCampanhasAllowUpload (bool)
 */

function ml_camp_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$summary = [];
$summaryMeta = [];
$loadError = null;

try {
    $listResult = $app['mlPromotionsService']->listCampaignSummaries($mlCampanhasItemStatus);
    $summary = is_array($listResult['summaries'] ?? null) ? $listResult['summaries'] : [];
    $summaryMeta = is_array($listResult['meta'] ?? null) ? $listResult['meta'] : [];
    if ($summary === []) {
        $raw = (int) ($summaryMeta['raw_total'] ?? 0);
        if ($raw === 0) {
            $loadError = 'A API do Mercado Livre nao retornou promocoes para o seller '
                . (string) ($summaryMeta['seller_id'] ?? '')
                . '. Confira Seller ID e token em Configuracao API.';
        } else {
            $loadError = 'Foram encontradas ' . $raw . ' promocoes na API, mas nenhuma aberta/disponivel neste filtro.';
        }
    }
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$uploadFeedback = null;
$uploadFeedbackClass = 'ok';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string) ($_POST['form_type'] ?? '') === 'ml_campanhas_upload'
    && $mlCampanhasAllowUpload
) {
    try {
        $file = $_FILES['campaign_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecione um arquivo Excel valido.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload invalido.');
        }
        $result = $app['mlPromotionsService']->processCampaignSpreadsheet($tmp);
        $uploadFeedback = sprintf(
            'Processamento concluido: %d inscritos, %d erros.',
            (int) $result['ok'],
            (int) $result['errors']
        );
        if ((int) $result['errors'] > 0) {
            $uploadFeedbackClass = 'err';
            $msgs = $result['messages'] ?? [];
            if (is_array($msgs) && $msgs !== []) {
                $uploadFeedback .= ' ' . implode(' | ', array_slice($msgs, 0, 5));
            }
        }
    } catch (Throwable $e) {
        $uploadFeedback = 'Erro: ' . $e->getMessage();
        $uploadFeedbackClass = 'err';
    }
}

$exportUrl = portal_wct_public_path($baseUrl, 'index.php?page=' . urlencode($mlCampanhasPageId) . '&ml_campanhas_action=export');
$uploadDemoUrl = portal_wct_public_path($baseUrl, 'index.php?page=' . urlencode($mlCampanhasPageId) . '&ml_campanhas_action=upload_demo');
$subnavPages = [
    'ml-campanhas' => 'Gerenciar',
    'ml-campanhas-pendentes' => 'Pendentes',
    'ml-campanhas-ativas' => 'Ativas',
];
?>
<section class="card protheus-monitor-card">
    <h1><?= ml_camp_h($mlCampanhasTitle) ?></h1>
    <p>
        Promocoes do Mercado Livre usando as credenciais em
        <a href="<?= ml_camp_h(portal_wct_public_path($baseUrl, 'index.php?page=api-config')) ?>">Configuracao API</a>.
    </p>

    <div class="protheus-legend" style="margin-bottom:12px;">
        <?php foreach ($subnavPages as $pid => $label): ?>
            <a href="<?= ml_camp_h(portal_wct_public_path($baseUrl, 'index.php?page=' . urlencode($pid))) ?>"
               class="legend-item ml-trigger-loading<?= $mlCampanhasPageId === $pid ? ' legend-status-active' : '' ?>"
               data-ml-loading-message="Carregando campanhas…"
               style="text-decoration:none;">
                <?= ml_camp_h($label) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($loadError !== null): ?>
        <p class="feedback err"><?= ml_camp_h($loadError) ?></p>
    <?php elseif (($summaryMeta['raw_total'] ?? 0) > 0): ?>
        <p style="font-size:.85rem;color:#64748b;margin:0 0 12px;">
            Seller <?= ml_camp_h((string) ($summaryMeta['seller_id'] ?? '')) ?> —
            <?= ml_camp_h((string) ($summaryMeta['shown'] ?? count($summary))) ?> campanha(s) exibida(s)
            de <?= ml_camp_h((string) ($summaryMeta['raw_total'] ?? 0)) ?> na API
            (filtro de itens: <?= ml_camp_h((string) ($summaryMeta['item_status'] ?? $mlCampanhasItemStatus)) ?>).
        </p>
    <?php endif; ?>

    <?php if ($uploadFeedback !== null): ?>
        <p class="feedback <?= ml_camp_h($uploadFeedbackClass) ?>"><?= ml_camp_h($uploadFeedback) ?></p>
    <?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0;align-items:flex-start;">
        <button type="button" id="ml-camp-download" class="btn primary">Download relatorio</button>
        <?php if ($mlCampanhasAllowUpload): ?>
            <button type="button" id="ml-camp-toggle-upload" class="btn">Subir planilha</button>
        <?php endif; ?>
    </div>

    <?php if ($mlCampanhasAllowUpload): ?>
        <div id="ml-camp-upload-panel" style="display:none;margin-bottom:16px;padding:16px;border:1px dashed #cbd5e1;border-radius:8px;background:#f8fafc;">
            <form method="post" enctype="multipart/form-data" data-ml-loading-message="Inscrevendo anuncios nas campanhas…">
                <input type="hidden" name="form_type" value="ml_campanhas_upload">
                <p style="margin:0 0 8px;font-weight:600;">Formato de upload (igual WCT Code)</p>
                <p style="margin:0 0 10px;font-size:.88rem;color:#475569;">
                    Planilha <strong>.xlsx</strong> com cabeçalho na primeira linha:
                    <code>MLB</code>, <code>TYPE</code>, <code>CODE</code>, <code>ID</code>
                    e opcional <code>PREÇO FINAL</code> (obrigatório para <code>SELLER_CAMPAIGN</code>).
                </p>
                <ul style="margin:0 0 12px;padding-left:1.2rem;font-size:.85rem;color:#64748b;">
                    <li><code>SMART</code> / <code>PRICE_MATCHING</code> / <code>UNHEALTHY_STOCK</code> — preencha <code>CODE</code> (offer_id)</li>
                    <li><code>MARKETPLACE_CAMPAIGN</code> — <code>CODE</code> pode ficar vazio</li>
                    <li><code>SELLER_CAMPAIGN</code> — informe <code>PREÇO FINAL</code> (ex.: 89,90)</li>
                </ul>
                <p style="margin:0 0 12px;">
                    <a class="btn ml-trigger-loading" href="<?= ml_camp_h($uploadDemoUrl) ?>" data-ml-loading-message="Baixando planilha demo…">
                        Baixar planilha demonstração
                    </a>
                </p>
                <input type="file" name="campaign_file" accept=".xlsx,.xls" required>
                <button type="submit" class="btn primary" style="margin-left:8px;">Participar</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th><input type="checkbox" id="ml-camp-select-all" aria-label="Selecionar todas"></th>
                <th>Nome</th>
                <th>Status</th>
                <th>Anuncios (<?= ml_camp_h($mlCampanhasItemStatus) ?>)</th>
                <th>Prazo inicial</th>
                <th>Prazo final</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($summary === []): ?>
                <tr>
                    <td colspan="6" style="text-align:center;color:#64748b;">Nenhuma campanha listada.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($summary as $item): ?>
                    <?php
                    $data = is_array($item['data'] ?? null) ? $item['data'] : [];
                    $cid = (string) ($data['id'] ?? '');
                    $ctype = (string) ($data['type'] ?? '');
                    $cname = (string) ($data['name'] ?? '');
                    ?>
                    <tr data-id="<?= ml_camp_h($cid) ?>" data-type="<?= ml_camp_h($ctype) ?>">
                        <td><input type="checkbox" class="ml-camp-row-cb" data-id="<?= ml_camp_h($cid) ?>" data-type="<?= ml_camp_h($ctype) ?>"></td>
                        <td>
                            <?php if ($ctype === 'DOD'): ?>
                                Ofertas do Dia
                            <?php elseif ($ctype === 'LIGHTNING'): ?>
                                Oferta Relampago
                            <?php else: ?>
                                <?= ml_camp_h($cname) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= ml_camp_h((string) ($data['status'] ?? '')) ?></td>
                        <td><?= ($item['total'] ?? null) === null ? '—' : ml_camp_h((string) $item['total']) ?></td>
                        <td><?= ml_camp_h((string) ($data['start_date'] ?? '—')) ?></td>
                        <td><?= ml_camp_h((string) ($data['end_date'] ?? '—')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(function () {
    const exportUrl = <?= json_encode($exportUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const itemStatus = <?= json_encode($mlCampanhasItemStatus, JSON_UNESCAPED_UNICODE) ?>;
    const selected = [];

    const btnDownload = document.getElementById('ml-camp-download');
    const btnToggle = document.getElementById('ml-camp-toggle-upload');
    const uploadPanel = document.getElementById('ml-camp-upload-panel');
    const selectAll = document.getElementById('ml-camp-select-all');

    function exportSelected() {
        if (selected.length === 0) {
            alert('Selecione ao menos 1 campanha.');
            return;
        }
        if (typeof window.MlLoading === 'undefined') {
            alert('Carregador indisponivel.');
            return;
        }
        window.MlLoading.downloadBlob(exportUrl, {
            message: 'Gerando relatorio de campanhas… pode levar alguns minutos.',
            filename: 'campanha.xlsx',
            fetchInit: {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                },
                body: JSON.stringify({ selected: selected, item_status: itemStatus }),
            },
        }).catch(function (err) {
            alert((err && err.message) ? err.message : 'Falha ao exportar campanhas.');
        });
    }

    function syncSelection(id, type, checked) {
        const idx = selected.findIndex(function (x) { return x.id === id; });
        if (checked && idx === -1) {
            selected.push({ id: id, type: type });
        } else if (!checked && idx !== -1) {
            selected.splice(idx, 1);
        }
    }

    document.querySelectorAll('.ml-camp-row-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
            syncSelection(cb.dataset.id, cb.dataset.type, cb.checked);
        });
    });

    document.querySelectorAll('tr[data-id]').forEach(function (row) {
        row.addEventListener('click', function (ev) {
            if (ev.target.tagName === 'INPUT') return;
            const cb = row.querySelector('.ml-camp-row-cb');
            if (!cb) return;
            cb.checked = !cb.checked;
            syncSelection(cb.dataset.id, cb.dataset.type, cb.checked);
        });
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            document.querySelectorAll('.ml-camp-row-cb').forEach(function (cb) {
                cb.checked = selectAll.checked;
                syncSelection(cb.dataset.id, cb.dataset.type, cb.checked);
            });
        });
    }

    if (btnToggle && uploadPanel) {
        btnToggle.addEventListener('click', function () {
            uploadPanel.style.display = uploadPanel.style.display === 'none' ? 'block' : 'none';
        });
    }

    if (btnDownload) {
        btnDownload.addEventListener('click', exportSelected);
    }
})();
</script>
