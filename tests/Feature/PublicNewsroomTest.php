<?php

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicNewsroomTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_article_is_visible(): void
    {
        $post = Post::factory()->published()->create(['slug' => 'visible-story']);

        $this->get('/articles/visible-story')->assertOk();
    }

    public function test_draft_article_is_not_visible(): void
    {
        Post::factory()->create(['slug' => 'hidden-draft']);

        $this->get('/articles/hidden-draft')->assertNotFound();
    }

    public function test_sitemap_returns_xml(): void
    {
        Post::factory()->published()->create();

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/xml');
    }

    public function test_rss_returns_xml(): void
    {
        Post::factory()->published()->create();

        $response = $this->get('/rss.xml');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/rss+xml');
    }

    public function test_channel_page_renders(): void
    {
        $this->get('/apes-cic')->assertOk();
    }

    public function test_search_finds_published_posts(): void
    {
        Post::factory()->published()->create(['title' => 'Unique Searchable Title']);

        $this->withoutVite();

        $this->get('/search?q=Unique+Searchable')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Search/Index')
                ->has('results', 1)
                ->where('results.0.title', 'Unique Searchable Title'));
    }
}
