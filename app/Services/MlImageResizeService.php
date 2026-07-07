<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Redimensionamento basico de imagens (migrado do WCT Code, sem TinyPNG).
 */
class MlImageResizeService
{
    private const MAX_SIZE = 2000;

    public function processUploadedFiles(array $files): string
    {
        if ($files === []) {
            throw new RuntimeException('Nenhuma imagem enviada.');
        }

        $workDir = $this->workDirectory() . DIRECTORY_SEPARATOR . 'batch_' . bin2hex(random_bytes(4));
        if (!mkdir($workDir, 0700, true) && !is_dir($workDir)) {
            throw new RuntimeException('Nao foi possivel criar pasta temporaria.');
        }

        $processed = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            $name = basename((string) ($file['name'] ?? 'image.jpg'));
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }

            $dest = $workDir . DIRECTORY_SEPARATOR . $name;
            if (!$this->resizeImage($tmp, $dest)) {
                continue;
            }
            $processed[] = $dest;
        }

        if ($processed === []) {
            $this->removeDirectory($workDir);
            throw new RuntimeException('Nenhuma imagem valida foi processada.');
        }

        $zipPath = $this->workDirectory() . DIRECTORY_SEPARATOR . 'imagens_' . date('Ymd_His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->removeDirectory($workDir);
            throw new RuntimeException('Nao foi possivel criar arquivo ZIP.');
        }

        foreach ($processed as $path) {
            $zip->addFile($path, basename($path));
        }
        $zip->close();
        $this->removeDirectory($workDir);

        return $zipPath;
    }

    public function getZipPath(string $fileName): ?string
    {
        $base = basename($fileName);
        if ($base === '' || str_contains($base, '..') || !str_ends_with(strtolower($base), '.zip')) {
            return null;
        }
        $path = $this->workDirectory() . DIRECTORY_SEPARATOR . $base;

        return is_file($path) ? $path : null;
    }

    private function resizeImage(string $source, string $dest): bool
    {
        $info = @getimagesize($source);
        if ($info === false) {
            return false;
        }

        [$width, $height, $type] = $info;
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => false,
        };

        if ($src === false) {
            return false;
        }

        $scale = min(1.0, self::MAX_SIZE / max($width, $height));
        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($newW, $newH);
        if ($dst === false) {
            imagedestroy($src);

            return false;
        }

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($src);

        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($dst, $dest, 85),
            IMAGETYPE_PNG => imagepng($dst, $dest, 6),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($dst, $dest, 85) : false,
            IMAGETYPE_GIF => imagegif($dst, $dest),
            default => false,
        };
        imagedestroy($dst);

        return (bool) $ok;
    }

    private function workDirectory(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal_wct-ml-images';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Nao foi possivel criar pasta de imagens.');
        }

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
