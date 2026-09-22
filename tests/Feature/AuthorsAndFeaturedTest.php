<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorsAndFeaturedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_featured_post_is_preferred_on_home_and_channel(): void
    {
        $staff = User::factory()->staff()->create();
        $olderFeatured = Post::factory()->published()->create([
            'author_id' => $staff->id,
            'slug' => 'featured-story',
            'title' => 'Featured Story',
            'featured' => true,
            'published_at' => now()->subDays(3),
            'channel' => 'apes_cic',
        ]);
        Post::factory()->published()->create([
            'author_id' => $staff->id,
            'slug' => 'latest-story',
            'title' => 'Latest Story',
            'featured' => false,
            'published_at' => now()->subHour(),
            'channel' => 'apes_cic',
        ]);

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->where('featured.slug', 'featured-story')
            ->where('featured.featured', true));

        $this->get('/apes-cic')->assertOk()->assertInertia(fn ($page) => $page
            ->where('posts.data.0.slug', 'featured-story'));

        $this->assertTrue($olderFeatured->fresh()->featured);
    }

    public function test_co_authors_sync_and_appear_on_public_article_and_archive(): void
    {
        $primary = User::factory()->staff()->create(['name' => 'Primary Author']);
        $co = User::factory()->staff()->create(['name' => 'Co Author']);
        $post = Post::factory()->published()->create([
            'author_id' => $primary->id,
            'slug' => 'shared-byline',
        ]);

        $this->actingAs($primary)->patch("/staff/posts/{$post->id}", [
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'channel' => $post->channel->value,
            'content' => $post->content,
            'featured' => true,
            'co_author_ids' => [$co->id],
            'tags' => [],
            'expected_updated_at' => $post->updated_at?->toIso8601String(),
        ])->assertRedirect();

        $post->refresh();
        $this->assertTrue($post->featured);
        $this->assertEqualsCanonicalizing(
            [$primary->id, $co->id],
            $post->authors()->pluck('users.id')->all(),
        );

        $this->get('/articles/shared-byline')->assertOk()->assertInertia(fn ($page) => $page
            ->has('article.authors', 2));

        $this->get('/authors/'.$co->id)->assertOk()->assertInertia(fn ($page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.slug', 'shared-byline'));
    }
}
