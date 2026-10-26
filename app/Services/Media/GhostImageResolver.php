<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Finds the file behind a Ghost image path and stores it as a media asset (#538).
 *
 * Sources, in order: files the content import already copied to
 * `storage/app/public/imports`, an extracted Ghost `content/images` folder,
 * then the legacy Ghost site. Requests only go to the configured source host.
 */
class GhostImageResolver
{
    /** @var array<string, array{found: bool, from: ?string, detail: string, asset: ?MediaAsset}> */
    private array $cache = [];

    public function __construct(
        private readonly HeroImageStore $store,
        private readonly GhostImageUrls $urls,
    ) {}

    /**
     * @return array{found: bool, from: ?string, detail: string, asset: ?MediaAsset}
     */
    public function resolve(string $url, ?string $mediaDir, ?string $source, bool $dryRun): array
    {
        $relative = $this->urls->relativePath($url);
        $key = ($dryRun ? 'check:' : 'store:').$relative;

        return $this->cache[$key] ??= $this->lookup($relative, $mediaDir, $source, $dryRun);
    }

    /**
     * @return array{found: bool, from: ?string, detail: string, asset: ?MediaAsset}
     */
    private function lookup(string $relative, ?string $mediaDir, ?string $source, bool $dryRun): array
    {
        $original = $this->urls->originalPath($relative);
        $basename = basename(parse_url($original, PHP_URL_PATH) ?: $original);
        $problems = [];

        $imported = 'imports/'.$basename;
        if ($basename !== '' && Storage::disk('public')->exists($imported)) {
            return $this->finish((string) Storage::disk('public')->get($imported), 'imports', $relative, null, $dryRun);
        }

        if ($mediaDir !== null && $mediaDir !== '') {
            $file = $this->findInMediaDir($mediaDir, $original, $basename);
            if ($file !== null) {
                return $this->finish((string) file_get_contents($file), 'media-dir', $relative, null, $dryRun);
            }
            $problems[] = 'not in --media-dir';
        }

        if ($source !== null && $source !== '') {
            foreach (array_unique([$original, $relative]) as $path) {
                $sourceUrl = rtrim($source, '/').$path;
                try {
                    $response = Http::timeout(20)
                        ->withOptions(['allow_redirects' => false])
                        ->accept('image/*')
                        ->get($sourceUrl);
                } catch (Throwable $e) {
                    $problems[] = "{$sourceUrl}: ".$e->getMessage();

                    continue;
                }

                if ($response->successful() && $response->body() !== '') {
                    return $this->finish($response->body(), 'source', $relative, $sourceUrl, $dryRun);
                }

                $problems[] = "{$sourceUrl}: HTTP {$response->status()}";
            }
        }

        return [
            'found' => false,
            'from' => null,
            'detail' => $problems === [] ? 'not found in imports and no other source given' : implode('; ', $problems),
            'asset' => null,
        ];
    }

    /**
     * @return array{found: bool, from: ?string, detail: string, asset: ?MediaAsset}
     */
    private function finish(string $bytes, string $from, string $relative, ?string $sourceUrl, bool $dryRun): array
    {
        if ($dryRun) {
            return ['found' => true, 'from' => $from, 'detail' => 'would copy from '.$from, 'asset' => null];
        }

        try {
            $asset = $this->store->storeBytes(
                $bytes,
                basename($relative),
                null,
                MediaAsset::SOURCE_GHOST_IMPORT,
                $sourceUrl ?? $relative,
                (int) config('newsroom.hero_uploads.import_max_kb', 25600),
            );
        } catch (InvalidImageException $e) {
            return ['found' => false, 'from' => $from, 'detail' => 'rejected: '.$e->getMessage(), 'asset' => null];
        }

        return ['found' => true, 'from' => $from, 'detail' => 'copied from '.$from, 'asset' => $asset];
    }

    private function findInMediaDir(string $mediaDir, string $original, string $basename): ?string
    {
        $root = rtrim($mediaDir, '/\\');
        $nested = substr($original, strlen('/content/images/'));
        foreach ([$root.'/'.$nested, $root.'/content/images/'.$nested, $root.'/images/'.$nested] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        if (! is_dir($root) || $basename === '') {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $basename) {
                return $file->getPathname();
            }
        }

        return null;
    }
}
