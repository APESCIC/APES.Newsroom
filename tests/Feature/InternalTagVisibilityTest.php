<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Publishing\PublicArticlePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalTagVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_hash_prefix_marks_tag_internal_and_hides_from_public(): void
    {
        $staff = User::factory()->staff()->create();
        $post = Post::factory()->published()->create(['author_id' => $staff->id]);

        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", [
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'channel' => $post->channel->value,
            'content' => $post->content,
            'tags' => ['News', '#internal-notes'],
            'expected_updated_at' => $post->updated_at?->toIso8601String(),
        ])->assertRedirect();

        $public = Tag::query()->where('slug', 'news')->first();
        $internal = Tag::query()->where('slug', 'internal-notes')->first();
        $this->assertNotNull($public);
        $this->assertFalse($public->is_internal);
        $this->assertNotNull($internal);
        $this->assertTrue($internal->is_internal);
        $this->assertStringStartsWith('#', $internal->name);

        $this->get('/tags/news')->assertOk();
        $this->get('/tags/internal-notes')->assertNotFound();
        $this->get('/tags/internal-notes/rss.xml')->assertNotFound();

        $payload = app(PublicArticlePresenter::class)->payload($post->fresh());
        $slugs = collect($payload['tags'])->pluck('slug')->all();
        $this->assertContains('news', $slugs);
        $this->assertNotContains('internal-notes', $slugs);

        $rss = $this->get('/tags/news/rss.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/articles/'.$post->slug, $rss);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/tags/news', $sitemap);
        $this->assertStringNotContainsString('/tags/internal-notes', $sitemap);
    }
}
