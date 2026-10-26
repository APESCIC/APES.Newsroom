<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Post;
use App\Services\Media\GhostImageUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestImages;
use Tests\TestCase;

class FixGhostImagesCommandTest extends TestCase
{
    use CreatesTestImages, RefreshDatabase;

    private const SOURCE = 'https://ghost-legacy.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    private function fakeSource(array $available): void
    {
        Http::fake(function (Request $request) use ($available) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return isset($available[$path])
                ? Http::response($available[$path], 200, ['Content-Type' => 'image/jpeg'])
                : Http::response('', 404);
        });
    }

    private function imageBlock(string $url): array
    {
        return ['type' => 'image', 'data' => ['file' => ['url' => $url], 'alt' => 'Body image']];
    }

    public function test_dry_run_lists_affected_items_and_images_without_changing_anything(): void
    {
        $this->fakeSource(['/content/images/2024/01/hero.jpg' => $this->jpegBytes(900, 600)]);
        $post = Post::factory()->published()->create([
            'title' => 'Capuchin update',
            'hero_image' => '__GHOST_URL__/content/images/2024/01/hero.jpg',
        ]);
        $page = Page::factory()->create([
            'title' => 'About APES',
            'content' => ['blocks' => [$this->imageBlock('http://localhost/content/images/2023/05/missing.jpg')]],
        ]);
        Post::factory()->create(['title' => 'Clean post', 'hero_image' => 'https://images.example.test/fine.jpg']);

        $this->artisan('media:fix-ghost-images', ['--dry-run' => true, '--source' => self::SOURCE])
            ->expectsOutputToContain('Dry run: nothing will be changed.')
            ->expectsOutputToContain("Post #{$post->id} \"Capuchin update\"")
            ->expectsOutputToContain('hero: __GHOST_URL__/content/images/2024/01/hero.jpg would copy from source')
            ->expectsOutputToContain("Page #{$page->id} \"About APES\"")
            ->expectsOutputToContain('body: http://localhost/content/images/2023/05/missing.jpg MISSING')
            ->doesntExpectOutputToContain('Clean post')
            ->assertSuccessful();

        $this->assertSame(0, MediaAsset::query()->count());
        $this->assertSame('__GHOST_URL__/content/images/2024/01/hero.jpg', $post->fresh()->hero_image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_run_copies_images_repoints_heroes_and_body_and_is_safe_to_repeat(): void
    {
        $heroBytes = $this->jpegBytes(1400, 900);
        $this->fakeSource(['/content/images/2024/01/hero.jpg' => $heroBytes]);
        Storage::disk('public')->put('imports/body.jpg', $this->jpegBytes(800, 500));

        $post = Post::factory()->published()->create([
            'hero_image' => '__GHOST_URL__/content/images/size/w1000/2024/01/hero.jpg',
            'hero_image_alt' => 'Capuchin',
            'content' => ['blocks' => [
                $this->imageBlock('http://localhost/content/images/2024/02/body.jpg'),
                ['type' => 'legacy', 'data' => ['html' => '<p><img src="__GHOST_URL__/content/images/2024/02/body.jpg"></p>']],
            ]],
        ]);
        $updatedAt = $post->updated_at?->toIso8601String();

        $this->artisan('media:fix-ghost-images', ['--source' => self::SOURCE])
            ->expectsOutputToContain('copied from source')
            ->expectsOutputToContain('copied from imports')
            ->assertSuccessful();

        $post->refresh();
        $this->assertSame(2, MediaAsset::query()->count());
        $hero = MediaAsset::query()->where('source_url', self::SOURCE.'/content/images/2024/01/hero.jpg')->sole();
        $this->assertSame(MediaAsset::SOURCE_GHOST_IMPORT, $hero->source);
        $this->assertSame($hero->id, $post->hero_media_id);
        $this->assertSame($hero->url(), $post->hero_image);

        $body = MediaAsset::query()->whereKeyNot($hero->id)->sole();
        $this->assertSame($body->url(), $post->content['blocks'][0]['data']['file']['url']);
        $this->assertSame('<p><img src="'.$body->url().'"></p>', $post->content['blocks'][1]['data']['html']);
        $this->assertSame($updatedAt, $post->updated_at?->toIso8601String());

        Http::assertSentCount(1);

        $this->artisan('media:fix-ghost-images', ['--source' => self::SOURCE])
            ->expectsOutputToContain('Image references')
            ->assertSuccessful();
        $this->assertSame(2, MediaAsset::query()->count());
        Http::assertSentCount(1);
    }

    public function test_missing_images_are_reported_and_left_unchanged(): void
    {
        $this->fakeSource([]);
        $post = Post::factory()->published()->create([
            'title' => 'Lost image',
            'hero_image' => '__GHOST_URL__/content/images/2020/01/gone.jpg',
        ]);

        $this->artisan('media:fix-ghost-images', ['--source' => self::SOURCE])
            ->expectsOutputToContain('Missing images (references left unchanged):')
            ->expectsOutputToContain('HTTP 404')
            ->assertSuccessful();

        $this->assertSame('__GHOST_URL__/content/images/2020/01/gone.jpg', $post->fresh()->hero_image);
        $this->assertSame(0, MediaAsset::query()->count());
    }

    public function test_media_dir_is_used_and_no_fetch_skips_the_legacy_site(): void
    {
        $dir = storage_path('framework/testing/ghost-media-'.uniqid());
        File::ensureDirectoryExists($dir.'/2024/03');
        file_put_contents($dir.'/2024/03/tortoise.jpg', $this->jpegBytes(700, 700));

        try {
            $post = Post::factory()->published()->create(['hero_image' => '/content/images/2024/03/tortoise.jpg']);

            $this->artisan('media:fix-ghost-images', ['--no-fetch' => true, '--media-dir' => $dir])
                ->expectsOutputToContain('copied from media-dir')
                ->assertSuccessful();

            $this->assertNotNull($post->fresh()->hero_media_id);
            Http::assertNothingSent();
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_no_public_page_references_ghost_urls_after_the_run(): void
    {
        $this->fakeSource(['/content/images/2024/01/found.jpg' => $this->jpegBytes(1000, 700)]);
        Post::factory()->published()->create([
            'slug' => 'fixed-hero',
            'featured' => true,
            'hero_image' => '__GHOST_URL__/content/images/2024/01/found.jpg',
            'hero_image_alt' => 'Found',
        ]);
        Post::factory()->published()->create([
            'slug' => 'still-missing',
            'hero_image' => '__GHOST_URL__/content/images/2024/01/lost.jpg',
            'content' => ['blocks' => [$this->imageBlock('__GHOST_URL__/content/images/2024/01/lost-body.jpg')]],
        ]);

        $this->artisan('media:fix-ghost-images', ['--source' => self::SOURCE])->assertSuccessful();

        foreach (['/', '/articles/fixed-hero', '/articles/still-missing', '/apes-cic', '/rss.xml'] as $uri) {
            $response = $this->get($uri);
            if ($response->status() === 404) {
                continue;
            }

            $this->assertStringNotContainsString('__GHOST_URL__', (string) $response->getContent(), $uri);
            $this->assertStringNotContainsString('/content/images/', (string) $response->getContent(), $uri);
        }

        $this->assertNotNull(Post::query()->where('slug', 'fixed-hero')->value('hero_media_id'));
        $this->assertNull(Post::query()->where('slug', 'still-missing')->value('hero_media_id'));
    }

    public function test_url_matcher_recognises_ghost_forms_and_ignores_other_hosts(): void
    {
        $urls = app(GhostImageUrls::class);

        $this->assertTrue($urls->isGhostImage('__GHOST_URL__/content/images/2024/01/a.jpg'));
        $this->assertTrue($urls->isGhostImage('/content/images/2024/01/a.jpg'));
        $this->assertTrue($urls->isGhostImage('https://www.apesnews.org.uk/content/images/a.jpg'));
        $this->assertTrue($urls->isGhostImage('http://localhost/content/images/a.jpg'));
        $this->assertFalse($urls->isGhostImage('https://cdn.example.test/content/images/a.jpg'));
        $this->assertFalse($urls->isGhostImage('http://localhost/storage/media/a.jpg'));

        $this->assertSame(
            ['__GHOST_URL__/content/images/a.jpg', '/content/images/b.png'],
            $urls->find('<img src="__GHOST_URL__/content/images/a.jpg"><img src="/content/images/b.png"><img src="https://cdn.example.test/content/images/c.jpg">'),
        );
        $this->assertSame('/content/images/2024/01/a.jpg', $urls->originalPath('/content/images/size/w1000/2024/01/a.jpg'));
    }
}
