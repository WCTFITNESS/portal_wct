<?php

declare(strict_types=1);

function ml_inact_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$pageNum = max(1, (int) ($_GET['p'] ?? 1));
$result = ['items' => [], 'total' => 0, 'current_page' => 1, 'total_pages' => 1];
$error = null;

try {
    $result = $app['mlInactiveAdsService']->listInactiveAds($pageNum, 15);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$exportUrl = portal_wct_public_path($baseUrl, 'index.php?page=ml-anuncios-inativos&export=xlsx');
?>
<section class="card protheus-monitor-card">
    <h1>Anuncios Inativos</h1>
    <p>
        Anuncios com status pendente, pausado ou em revisao.
        Credenciais em
        <a href="<?= ml_inact_h(portal_wct_public_path($baseUrl, 'index.php?page=api-config')) ?>">Configuracao API</a>.
    </p>

    <?php if ($error !== null): ?>
        <p class="feedback err"><?= ml_inact_h($error) ?></p>
    <?php endif; ?>

    <p style="margin:12px 0;">
        <a class="btn primary ml-trigger-loading" href="<?= ml_inact_h($exportUrl) ?>" data-ml-loading-message="Exportando anuncios inativos…">
            Exportar Excel (todos)
        </a>
    </p>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Imagem</th>
                <th>MLB</th>
                <th>SKU</th>
                <th>Titulo</th>
                <th>Status</th>
                <th>Motivo</th>
                <th>Estoque</th>
                <th>Full</th>
            </tr>
            </thead>
            <tbody>
            <?php if (($result['items'] ?? []) === []): ?>
                <tr>
                    <td colspan="8" style="text-align:center;color:#64748b;">Nenhum anuncio inativo nesta pagina.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($result['items'] as $item): ?>
                    <tr>
                        <td>
                            <?php if (($item['thumbnail'] ?? '') !== ''): ?>
                                <img src="<?= ml_inact_h((string) $item['thumbnail']) ?>" alt="" width="48" height="48" style="object-fit:cover;border-radius:4px;">
                            <?php endif; ?>
                        </td>
                        <td><?= ml_inact_h((string) ($item['id'] ?? '')) ?></td>
                        <td><?= ml_inact_h((string) ($item['sku'] ?? '')) ?></td>
                        <td>
                            <?php if (($item['permalink'] ?? '') !== ''): ?>
                                <a href="<?= ml_inact_h((string) $item['permalink']) ?>" target="_blank" rel="noopener">
                                    <?= ml_inact_h((string) ($item['title'] ?? '')) ?>
                                </a>
                            <?php else: ?>
                                <?= ml_inact_h((string) ($item['title'] ?? '')) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= ml_inact_h((string) ($item['status'] ?? '')) ?></td>
                        <td><?= ml_inact_h((string) ($item['status_detail'] ?? '')) ?></td>
                        <td><?= ml_inact_h((string) ($item['stock'] ?? '')) ?></td>
                        <td><?= ml_inact_h((string) ($item['fulfillment'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ((int) ($result['total_pages'] ?? 1) > 1): ?>
        <nav style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
            <?php for ($p = 1; $p <= (int) $result['total_pages']; $p++): ?>
                <?php
                $href = portal_wct_public_path($baseUrl, 'index.php?page=ml-anuncios-inativos&p=' . $p);
                $active = $p === (int) ($result['current_page'] ?? 1);
                ?>
                <a href="<?= ml_inact_h($href) ?>" class="btn<?= $active ? ' primary' : '' ?><?= $active ? '' : ' ml-trigger-loading' ?>"<?= $active ? '' : ' data-ml-loading-message="Carregando pagina…"' ?>><?= $p ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
</section>
