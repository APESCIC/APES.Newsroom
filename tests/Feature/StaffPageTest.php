<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_staff_can_create_and_publish_a_page(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/pages', [
            'title' => 'About Us',
            'slug' => 'about-us',
            'excerpt' => 'Who we are',
            'content' => [
                'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Hello']]],
            ],
        ])->assertRedirect();

        $page = Page::query()->where('slug', 'about-us')->first();
        $this->assertNotNull($page);
        $this->assertSame(PostStatus::Draft, $page->status);

        $this->actingAs($staff)->post("/staff/pages/{$page->id}/publish")->assertRedirect();
        $page->refresh();
        $this->assertSame(PostStatus::Published, $page->status);
        $this->assertNotNull($page->published_at);
    }

    public function test_published_page_is_public_and_not_in_news_surfaces(): void
    {
        $staff = User::factory()->staff()->create();
        $page = Page::factory()->published()->create([
            'author_id' => $staff->id,
            'slug' => 'about',
            'title' => 'About',
        ]);
        $article = Post::factory()->published()->create([
            'author_id' => $staff->id,
            'slug' => 'news-item',
            'title' => 'News Item',
        ]);

        $this->get('/pages/about')->assertOk()
            ->assertInertia(fn ($assert) => $assert
                ->component('Pages/Show')
                ->where('page.slug', 'about'));

        $this->get('/')->assertOk()->assertInertia(fn ($assert) => $assert
            ->where('featured.slug', 'news-item')
            ->has('recent', 0));

        $rss = $this->get('/rss.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/articles/news-item', $rss);
        $this->assertStringNotContainsString('/pages/about', $rss);

        $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/pages/about', $sitemap);
        $this->assertStringContainsString('/articles/'.$article->slug, $sitemap);
    }

    public function test_draft_page_is_not_public(): void
    {
        $page = Page::factory()->create(['slug' => 'secret']);

        $this->get('/pages/secret')->assertNotFound();
    }

    public function test_guest_cannot_access_staff_pages(): void
    {
        $this->get('/staff/pages')->assertRedirect();
    }

    public function test_public_user_cannot_access_staff_pages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/staff/pages')->assertForbidden();
    }
}
