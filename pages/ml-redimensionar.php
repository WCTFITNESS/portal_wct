<?php

declare(strict_types=1);

function ml_img_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$feedback = null;
$feedbackClass = 'ok';
$downloadName = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (string) ($_POST['form_type'] ?? '') === 'ml_images_upload'
) {
    try {
        $files = [];
        if (isset($_FILES['images']) && is_array($_FILES['images']['name'] ?? null)) {
            $count = count($_FILES['images']['name']);
            for ($i = 0; $i < $count; $i++) {
                $files[] = [
                    'name' => $_FILES['images']['name'][$i] ?? '',
                    'type' => $_FILES['images']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['images']['tmp_name'][$i] ?? '',
                    'error' => $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $_FILES['images']['size'][$i] ?? 0,
                ];
            }
        }

        $zipPath = $app['mlImageResizeService']->processUploadedFiles($files);
        $downloadName = basename($zipPath);
        $feedback = 'Imagens processadas. Clique em baixar ZIP.';
    } catch (Throwable $e) {
        $feedback = 'Erro: ' . $e->getMessage();
        $feedbackClass = 'err';
    }
}
?>
<section class="card protheus-monitor-card">
    <h1>Redimensionar Imagens</h1>
    <p>
        Redimensiona imagens para ate 2000×2000 px (JPEG/PNG/WebP/GIF).
        Equivalente ao modulo do WCT Code, usando GD do PHP.
    </p>

    <?php if ($feedback !== null): ?>
        <p class="feedback <?= ml_img_h($feedbackClass) ?>"><?= ml_img_h($feedback) ?></p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" style="margin:16px 0;">
        <input type="hidden" name="form_type" value="ml_images_upload">
        <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
        <button type="submit" class="btn primary" style="margin-left:8px;">Processar</button>
    </form>

    <?php if ($downloadName !== null): ?>
        <p>
            <a class="btn primary"
               href="<?= ml_img_h(portal_wct_public_path($baseUrl, 'index.php?page=ml-redimensionar&download=' . urlencode($downloadName))) ?>">
                Baixar ZIP
            </a>
        </p>
    <?php endif; ?>
</section>
