<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config\Settings;
use App\Repo\ArticleRepo;
use App\Support\Paths;
use Verstka\Sdk\Storage\StorageAdapter;

final class CmsVerstkaStorage implements StorageAdapter
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ArticleRepo $repo,
    ) {
    }

    /** @param array<string, mixed> $metadata */
    public function saveMedia(
        string $filename,
        string $tempPath,
        string $materialId,
        array $metadata,
    ): string {
        unset($metadata);
        $rel = $this->articleRelDir($materialId);
        $destDir = $this->settings->storageDir . '/' . $rel;
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }
        $dest = $destDir . '/' . $filename;
        if (!copy($tempPath, $dest)) {
            throw new \RuntimeException("failed to save media: {$filename}");
        }
        $base = rtrim($this->settings->publicBaseUrl, '/');

        return "{$base}/{$rel}/{$filename}";
    }

    /** @param array<string, mixed> $metadata */
    public function saveFontFile(
        string $filename,
        string $tempPath,
        string $materialId,
        array $metadata,
    ): string {
        unset($materialId, $metadata);
        $destDir = $this->settings->storageDir . '/fonts';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }
        $dest = $destDir . '/' . $filename;
        if (!copy($tempPath, $dest)) {
            throw new \RuntimeException("failed to save font: {$filename}");
        }
        $base = rtrim($this->settings->publicBaseUrl, '/');

        return "{$base}/fonts/{$filename}";
    }

    /** @param array<string, mixed> $metadata */
    public function saveFontsManifest(
        string $filename,
        string $tempPath,
        string $materialId,
        array $metadata,
    ): string {
        return $this->saveFontFile($filename, $tempPath, $materialId, $metadata);
    }

    private function articleRelDir(string $materialId): string
    {
        $row = $this->repo->articleByMaterialId($materialId);
        if ($row === null) {
            throw new \RuntimeException("unknown material_id for storage: {$materialId}");
        }

        return Paths::pathToStorageRelative((string) $row['path']);
    }
}
