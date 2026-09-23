<?php

namespace Tests\Feature\Api;

use App\Enums\ContentVisibility;
use App\Enums\PostStatus;
use App\Models\Page;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['newsroom.content_api.key' => 'test-content-key']);
    }

    public function test_missing_key_is_unauthorized(): void
    {
        $this->getJson('/api/content/v1/posts')->assertUnauthorized();
    }

    public function test_unconfigured_key_returns_service_unavailable(): void
    {
        config(['newsroom.content_api.key' => '']);

        $this->withHeader('X-Newsroom-Content-Key', 'anything')
            ->getJson('/api/content/v1/posts')
            ->assertStatus(503);
    }

    public function test_lists_only_published_posts_and_omits_html_on_index(): void
    {
        Post::factory()->create([
            'status' => PostStatus::Draft,
            'slug' => 'draft-post',
        ]);
        $published = Post::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
            'slug' => 'live-post',
            'visibility' => ContentVisibility::Public,
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Hello body']]]],
        ]);

        $this->withHeader('X-Newsroom-Content-Key', 'test-content-key')
            ->getJson('/api/content/v1/posts')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'live-post')
            ->assertJsonMissingPath('data.0.html');

        $this->withHeader('Authorization', 'Newsroom-Key test-content-key')
            ->getJson('/api/content/v1/posts/'.$published->slug)
            ->assertOk()
            ->assertJsonPath('data.slug', 'live-post')
            ->assertJsonPath('data.gated', false)
            ->assertJsonPath('data.html', fn ($html) => is_string($html) && str_contains($html, 'Hello body'));
    }

    public function test_gated_visibility_omits_html_on_show(): void
    {
        $post = Post::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
            'visibility' => ContentVisibility::Members,
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Secret']]]],
        ]);

        $this->withHeader('X-Newsroom-Content-Key', 'test-content-key')
            ->getJson('/api/content/v1/posts/'.$post->slug)
            ->assertOk()
            ->assertJsonPath('data.gated', true)
            ->assertJsonPath('data.html', null);
    }

    public function test_pages_and_public_tags(): void
    {
        Page::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
            'slug' => 'about',
            'visibility' => ContentVisibility::Public,
        ]);
        Tag::query()->create(['name' => 'News', 'slug' => 'news', 'is_internal' => false]);
        Tag::query()->create(['name' => '#internal', 'slug' => 'internal', 'is_internal' => true]);

        $this->withHeader('X-Newsroom-Content-Key', 'test-content-key')
            ->getJson('/api/content/v1/pages')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'about');

        $this->withHeader('X-Newsroom-Content-Key', 'test-content-key')
            ->getJson('/api/content/v1/tags')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.slug', 'news');
    }

    public function test_content_api_is_rate_limited(): void
    {
        config(['newsroom.content_api.rate_per_minute' => 2]);

        $this->withHeader('X-Newsroom-Content-Key', 'test-content-key')
            ->getJson('/api/content/v1/posts')
            ->assertOk();
        $this->withHeader('X-Newsroom-Content-Key', 'test-content-key')
            ->getJson('/api/content/v1/posts')
            ->assertOk();
        $this->withHeader('X-Newsroom-Content-Key', 'test-content-key')
            ->getJson('/api/content/v1/posts')
            ->assertStatus(429);
    }
}
