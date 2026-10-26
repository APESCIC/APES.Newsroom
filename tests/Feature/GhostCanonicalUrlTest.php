<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GhostCanonicalUrlTest extends TestCase
{
    use RefreshDatabase;

    private const GHOST_CANONICAL = '__GHOST_URL__/p/72b2b66c-98cd-4c58-a5d9-723852c08192/?member_status=free';

    public function test_articles_and_pages_never_render_a_ghost_placeholder_canonical(): void
    {
        Post::factory()->published()->create(['slug' => 'ghost-canonical', 'canonical_url' => self::GHOST_CANONICAL]);
        Page::factory()->create([
            'slug' => 'ghost-canonical-page',
            'status' => 'published',
            'published_at' => now()->subDay(),
            'canonical_url' => self::GHOST_CANONICAL,
        ]);

        foreach (['/articles/ghost-canonical', '/pages/ghost-canonical-page'] as $uri) {
            $this->get($uri)->assertOk()->assertDontSee('__GHOST_URL__', false);
        }
    }

    public function test_real_canonical_urls_are_kept(): void
    {
        Post::factory()->published()->create(['slug' => 'syndicated', 'canonical_url' => 'https://www.apes.org.uk/news/original']);

        $this->get('/articles/syndicated')->assertOk()->assertSee('https:\/\/www.apes.org.uk\/news\/original', false);
    }

    public function test_migration_clears_only_ghost_placeholder_canonicals(): void
    {
        $ghost = Post::factory()->create(['canonical_url' => self::GHOST_CANONICAL]);
        $real = Post::factory()->create(['canonical_url' => 'https://www.apes.org.uk/news/original']);
        $page = Page::factory()->create(['canonical_url' => self::GHOST_CANONICAL]);

        $migration = require database_path('migrations/2026_10_27_110000_clear_ghost_placeholder_canonical_urls.php');
        $migration->up();

        $this->assertNull($ghost->fresh()->canonical_url);
        $this->assertNull($page->fresh()->canonical_url);
        $this->assertSame('https://www.apes.org.uk/news/original', $real->fresh()->canonical_url);
    }
}
