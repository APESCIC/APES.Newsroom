<?php

namespace Tests\Feature\Seo;

use App\Models\Post;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitemapRobotsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sitemap_index_lists_child_sitemaps(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<sitemapindex', false)
            ->assertSee('/sitemap-posts.xml', false)
            ->assertSee('/sitemap-news.xml', false);
    }

    #[Test]
    public function news_sitemap_includes_posts_from_last_48_hours(): void
    {
        Post::factory()->published()->create([
            'slug' => 'fresh-story',
            'published_at' => now()->subHours(2),
        ]);
        Post::factory()->published()->create([
            'slug' => 'old-story',
            'published_at' => now()->subDays(5),
        ]);

        $this->get('/sitemap-news.xml')
            ->assertOk()
            ->assertSee('xmlns:news=', false)
            ->assertSee('/articles/fresh-story', false)
            ->assertDontSee('/articles/old-story');
    }

    #[Test]
    public function robots_txt_includes_sitemap_line_and_admin_body(): void
    {
        app(SettingsRepository::class)->set(
            SettingDefinitions::SEO_ROBOTS_TXT,
            "User-agent: *\nDisallow: /account",
        );

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /account', false)
            ->assertSee('Sitemap: '.url('/sitemap.xml'), false);
    }
}
