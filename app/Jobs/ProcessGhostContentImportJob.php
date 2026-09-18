<?php

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\Import\GhostContentImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;

class ProcessGhostContentImportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $importRunId,
        public bool $dryRun,
        public bool $cleanupAfter = false,
    ) {}

    public function handle(GhostContentImporter $importer): void
    {
        $run = ImportRun::query()->findOrFail($this->importRunId);
        $jsonPath = (string) $run->source_path;
        $mediaPath = $this->resolveMediaPath($jsonPath);

        try {
            $importer->import(
                $jsonPath,
                $mediaPath,
                $this->dryRun,
                $run->actor,
                $run,
            );
        } finally {
            if ($this->cleanupAfter) {
                $this->cleanupUploadDirectory($jsonPath);
            }
        }
    }

    private function resolveMediaPath(string $jsonPath): ?string
    {
        $mediaDir = dirname($jsonPath).DIRECTORY_SEPARATOR.'media';

        return is_dir($mediaDir) ? $mediaDir : null;
    }

    private function cleanupUploadDirectory(string $jsonPath): void
    {
        $dir = dirname($jsonPath);
        if ($dir === '' || $dir === '/' || $dir === '.' || ! is_dir($dir)) {
            return;
        }

        // Only delete known upload staging directories.
        $normalized = str_replace('\\', '/', $dir);
        if (! str_contains($normalized, '/imports/ghost-content/')) {
            return;
        }

        File::deleteDirectory($dir);
    }
}
