<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ArticleMediaSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_article_payload_includes_hero_and_canonical_fields(): void
    {
        $post = Post::factory()->published()->create([
            'slug' => 'hero-story',
            'hero_image' => 'https://example.test/hero.jpg',
            'hero_image_alt' => 'A capuchin monkey in habitat',
            'hero_image_caption' => 'Morning light in the enclosure',
            'hero_image_credit' => 'APES CIC / Jane Keeper',
            'canonical_url' => 'https://canonical.example/hero-story',
        ]);

        $this->get('/articles/hero-story')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Articles/Show')
                ->where('preview', false)
                ->where('article.hero_image', 'https://example.test/hero.jpg')
                ->where('article.hero_image_alt', 'A capuchin monkey in habitat')
                ->where('article.hero_image_caption', 'Morning light in the enclosure')
                ->where('article.hero_image_credit', 'APES CIC / Jane Keeper')
                ->where('article.canonical_url', 'https://canonical.example/hero-story')
                ->where('article.url', route('articles.show', $post->slug, absolute: true)));
    }

    public function test_published_article_payload_falls_back_when_optional_media_and_canonical_are_missing(): void
    {
        $post = Post::factory()->published()->create([
            'slug' => 'plain-story',
            'hero_image' => '',
            'hero_image_alt' => '   ',
            'hero_image_caption' => null,
            'hero_image_credit' => null,
            'canonical_url' => null,
        ]);

        $this->get('/articles/plain-story')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Articles/Show')
                ->where('article.hero_image', null)
                ->where('article.hero_image_alt', null)
                ->where('article.hero_image_caption', null)
                ->where('article.hero_image_credit', null)
                ->where('article.canonical_url', null)
                ->where('article.url', route('articles.show', $post->slug, absolute: true)));
    }

    public function test_preview_payload_includes_hero_canonical_and_preview_flag(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'slug' => 'draft-hero',
            'hero_image' => 'https://example.test/draft-hero.jpg',
            'hero_image_alt' => 'Draft hero alt',
            'hero_image_caption' => 'Draft caption',
            'hero_image_credit' => 'Draft credit',
            'canonical_url' => 'https://canonical.example/draft-hero',
        ]);

        $this->actingAs($admin)
            ->get("/staff/posts/{$post->id}/preview")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Articles/Show')
                ->where('preview', true)
                ->where('article.hero_image', 'https://example.test/draft-hero.jpg')
                ->where('article.hero_image_alt', 'Draft hero alt')
                ->where('article.hero_image_caption', 'Draft caption')
                ->where('article.hero_image_credit', 'Draft credit')
                ->where('article.canonical_url', 'https://canonical.example/draft-hero')
                ->where('article.url', route('articles.show', $post->slug, absolute: true)));
    }
}
