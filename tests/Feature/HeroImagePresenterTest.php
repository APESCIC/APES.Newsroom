<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Post;
use App\Services\Media\HeroImagePresenter;
use App\Services\Media\HeroImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesTestImages;
use Tests\TestCase;

class HeroImagePresenterTest extends TestCase
{
    use CreatesTestImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function readyAsset(int $width = 2400, int $height = 1600): MediaAsset
    {
        return app(HeroImageStore::class)->storeBytes($this->jpegBytes($width, $height), 'hero.jpg', null)->fresh();
    }

    public function test_uploaded_hero_has_srcset_focal_point_and_social_image(): void
    {
        $asset = $this->readyAsset();
        $post = Post::factory()->published()->create([
            'hero_image' => $asset->url(),
            'hero_media_id' => $asset->id,
            'hero_focal_x' => 0.3,
            'hero_focal_y' => 0.7,
        ]);

        $hero = app(HeroImagePresenter::class)->present($post->fresh());

        $this->assertStringContainsString('/storage/'.$asset->directory().'/w2000.jpg', (string) $hero['hero_image']);
        $this->assertNotNull($hero['hero_image_srcset']);
        $this->assertStringContainsString('w640.webp 640w', $hero['hero_image_srcset']['webp']);
        $this->assertStringContainsString('w2000.jpg 2000w', $hero['hero_image_srcset']['jpeg']);
        $this->assertSame(['x' => 0.3, 'y' => 0.7], $hero['hero_image_focal']);
        $this->assertSame(2000, $hero['hero_image_width']);
        $this->assertSame(1333, $hero['hero_image_height']);
        $this->assertStringContainsString('social-x50y50.jpg', (string) $hero['hero_image_social']);
    }

    public function test_external_hero_url_is_passed_through_without_srcset(): void
    {
        $post = Post::factory()->published()->create([
            'hero_image' => 'https://images.unsplash.com/photo-1?w=1600',
        ]);

        $hero = app(HeroImagePresenter::class)->present($post);

        $this->assertSame('https://images.unsplash.com/photo-1?w=1600', $hero['hero_image']);
        $this->assertNull($hero['hero_image_srcset']);
        $this->assertNull($hero['hero_image_focal']);
        $this->assertSame('https://images.unsplash.com/photo-1?w=1600', $hero['hero_image_social']);
    }

    public function test_ghost_placeholder_urls_are_never_rendered(): void
    {
        $post = Post::factory()->published()->create([
            'slug' => 'ghost-hero',
            'hero_image' => '__GHOST_URL__/content/images/2024/01/capuchin.jpg',
            'hero_image_alt' => 'A capuchin',
        ]);
        Page::factory()->create([
            'slug' => 'ghost-page',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'hero_image' => '__GHOST_URL__/content/images/2024/01/about.jpg',
        ]);

        $this->assertNull(app(HeroImagePresenter::class)->present($post)['hero_image']);

        foreach (['/', '/articles/ghost-hero', '/pages/ghost-page'] as $uri) {
            $response = $this->get($uri)->assertOk();
            $this->assertStringNotContainsString('__GHOST_URL__', (string) $response->getContent(), $uri);
        }
    }

    public function test_home_page_cards_carry_responsive_hero_data(): void
    {
        $asset = $this->readyAsset(1300, 800);
        Post::factory()->published()->create([
            'featured' => true,
            'hero_image' => $asset->url(),
            'hero_image_alt' => 'Rescued parrot',
            'hero_media_id' => $asset->id,
            'hero_focal_x' => 0.2,
            'hero_focal_y' => 0.4,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('home')
                ->where('featured.hero_image_alt', 'Rescued parrot')
                ->where('featured.hero_image_focal', ['x' => 0.2, 'y' => 0.4])
                ->where('featured.hero_image_srcset.webp', fn (string $srcset) => str_contains($srcset, '1300w'))
            );
    }
}
