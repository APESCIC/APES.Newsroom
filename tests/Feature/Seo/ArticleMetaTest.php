<?php

namespace Tests\Feature\Seo;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ArticleMetaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function published_article_html_includes_article_og_twitter_and_json_ld(): void
    {
        $post = Post::factory()->published()->create([
            'slug' => 'seo-story',
            'title' => 'Capuchin corridor update',
            'excerpt' => 'Keepers opened a new corridor this morning.',
            'meta_title' => null,
            'meta_description' => null,
            'hero_image' => 'https://example.test/hero.jpg',
            'hero_image_alt' => 'A capuchin on a branch',
        ]);

        $response = $this->get('/articles/seo-story');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('property="og:type" content="article"', $html);
        $this->assertStringContainsString('property="og:site_name" content="APES Newsroom"', $html);
        $this->assertStringContainsString('property="og:locale" content="en_GB"', $html);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $html);
        $this->assertStringContainsString('property="article:published_time"', $html);
        $this->assertStringContainsString('property="og:image:width" content="1200"', $html);
        $this->assertStringContainsString('property="og:image:alt" content="A capuchin on a branch"', $html);
        $this->assertStringContainsString('hreflang="en-GB"', $html);
        $this->assertStringContainsString('"@type":"NewsArticle"', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
        $this->assertStringContainsString('"@type":"WebSite"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('Capuchin corridor update | APES Newsroom', $html);
        $this->assertStringContainsString('Keepers opened a new corridor this morning.', $html);
        $this->assertStringContainsString(route('articles.show', $post->slug, absolute: true), $html);
    }

    #[Test]
    public function noindex_post_is_excluded_from_sitemaps_and_emits_robots(): void
    {
        Post::factory()->published()->create([
            'slug' => 'hidden-story',
            'seo_noindex' => true,
        ]);

        $this->get('/articles/hidden-story')
            ->assertOk()
            ->assertSee('name="robots" content="noindex,nofollow"', false);

        $this->get('/sitemap-posts.xml')
            ->assertOk()
            ->assertDontSee('/articles/hidden-story');
    }
}
