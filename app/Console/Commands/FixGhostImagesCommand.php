<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Post;
use App\Services\Media\GhostImageResolver;
use App\Services\Media\GhostImageUrls;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Copies imported Ghost images into Newsroom storage and repoints posts and
 * pages at them (#538). Safe to run repeatedly: fixed references no longer
 * match, and identical files are deduplicated by checksum. Images that cannot
 * be found are reported and their references left unchanged.
 */
class FixGhostImagesCommand extends Command
{
    protected $signature = 'media:fix-ghost-images
        {--dry-run : List affected posts, pages and images without changing anything}
        {--source= : Legacy Ghost site to fetch images from (defaults to NEWSROOM_GHOST_IMAGE_SOURCE)}
        {--no-fetch : Do not fetch from the legacy Ghost site; use local files only}
        {--media-dir= : Extracted Ghost content/images folder to copy from}';

    protected $description = 'Copy __GHOST_URL__ and /content/images hero and body images into Newsroom storage.';

    /** @var array{items: int, references: int, fixed: int, missing: int, sources: array<string, int>} */
    private array $totals = ['items' => 0, 'references' => 0, 'fixed' => 0, 'missing' => 0, 'sources' => []];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $missing = [];

    public function __construct(
        private readonly GhostImageUrls $urls,
        private readonly GhostImageResolver $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $source = $this->option('no-fetch') ? null : (string) ($this->option('source') ?: config('newsroom.hero_uploads.ghost_source'));
        $mediaDir = $this->option('media-dir') ? (string) $this->option('media-dir') : null;

        if ($mediaDir !== null && ! is_dir($mediaDir)) {
            $this->error("--media-dir {$mediaDir} is not a directory.");

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Dry run: nothing will be changed.' : 'Fixing Ghost image references.');
        $this->line('Sources: imports'.($mediaDir ? ", {$mediaDir}" : '').($source ? ", {$source}" : ''));
        $this->newLine();

        foreach ([Post::class => 'Post', Page::class => 'Page'] as $model => $label) {
            $model::query()->orderBy('id')->chunkById(100, function ($items) use ($label, $dryRun, $source, $mediaDir) {
                foreach ($items as $item) {
                    $this->processItem($item, $label, $dryRun, $source, $mediaDir);
                }
            });
        }

        $this->newLine();
        $this->table(['Posts and pages affected', 'Image references', $dryRun ? 'Can be fixed' : 'Fixed', 'Missing'], [[
            $this->totals['items'],
            $this->totals['references'],
            $this->totals['fixed'],
            $this->totals['missing'],
        ]]);

        foreach ($this->totals['sources'] as $from => $count) {
            $this->line("  from {$from}: {$count}");
        }

        if ($this->missing !== []) {
            $this->newLine();
            $this->warn('Missing images (references left unchanged):');
            $this->table(['Item', 'Image', 'Reason'], $this->missing);
        }

        return self::SUCCESS;
    }

    private function processItem(Post|Page $item, string $label, bool $dryRun, ?string $source, ?string $mediaDir): void
    {
        $heroUrl = $this->urls->isGhostImage($item->hero_image) ? trim((string) $item->hero_image) : null;
        $content = is_array($item->content) ? $item->content : [];
        $bodyUrls = $this->collectUrls($content);

        if ($heroUrl === null && $bodyUrls === []) {
            return;
        }

        $name = "{$label} #{$item->id} \"{$item->title}\"";
        $this->totals['items']++;
        $this->line($name);

        $replacements = [];
        $heroAsset = null;
        $references = array_merge($heroUrl !== null ? [['hero', $heroUrl]] : [], array_map(fn (string $url) => ['body', $url], $bodyUrls));

        foreach ($references as [$where, $url]) {
            $this->totals['references']++;
            $result = $this->resolver->resolve($url, $mediaDir, $source, $dryRun);

            if (! $result['found']) {
                $this->totals['missing']++;
                $this->missing[] = ["{$name} ({$where})", $url, $result['detail']];
                $this->line("  {$where}: {$url} MISSING ({$result['detail']})");

                continue;
            }

            $this->totals['fixed']++;
            $this->totals['sources'][(string) $result['from']] = ($this->totals['sources'][(string) $result['from']] ?? 0) + 1;
            $this->line("  {$where}: {$url} {$result['detail']}");

            if ($result['asset'] !== null) {
                $replacements[$url] = $result['asset']->url();
                if ($where === 'hero') {
                    $heroAsset = $result['asset'];
                }
            }
        }

        if ($dryRun || $replacements === []) {
            return;
        }

        if ($heroAsset !== null) {
            $item->hero_image = $heroAsset->url();
            $item->hero_media_id = $heroAsset->id;
        }

        if ($bodyUrls !== []) {
            $item->content = $this->replaceUrls($content, $replacements);
        }

        $this->saveWithoutTouching($item);
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private function collectUrls(array $value): array
    {
        $found = [];
        array_walk_recursive($value, function ($leaf) use (&$found) {
            if (is_string($leaf)) {
                array_push($found, ...$this->urls->find($leaf));
            }
        });

        return array_values(array_unique($found));
    }

    /**
     * strtr() tries longer keys first and never rescans replaced text, so a
     * full `__GHOST_URL__/content/images/a.jpg` wins over the bare path inside it.
     *
     * @param  array<mixed>  $value
     * @param  array<string, string>  $replacements
     * @return array<mixed>
     */
    private function replaceUrls(array $value, array $replacements): array
    {
        array_walk_recursive($value, function (&$leaf) use ($replacements) {
            if (is_string($leaf) && str_contains($leaf, '/content/images/')) {
                $leaf = strtr($leaf, $replacements);
            }
        });

        return $value;
    }

    private function saveWithoutTouching(Model $item): void
    {
        $item->timestamps = false;
        $item->save();
        $item->timestamps = true;
    }
}
