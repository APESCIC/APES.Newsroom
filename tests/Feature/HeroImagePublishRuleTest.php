<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Publishing\EditorialPostWriter;
use App\Services\Publishing\HeroImageRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HeroImagePublishRuleTest extends TestCase
{
    use RefreshDatabase;

    private function postWithHero(?string $alt, array $attributes = []): Post
    {
        return Post::factory()->create([
            'hero_image' => 'https://images.example.test/hero.jpg',
            'hero_image_alt' => $alt,
            ...$attributes,
        ]);
    }

    public function test_staff_publish_is_blocked_without_hero_alt_text(): void
    {
        $admin = User::factory()->admin()->create();
        $post = $this->postWithHero('');

        $this->actingAs($admin)
            ->from("/staff/posts/{$post->id}/edit")
            ->post("/staff/posts/{$post->id}/publish")
            ->assertRedirect("/staff/posts/{$post->id}/edit")
            ->assertSessionHasErrors(['hero_image_alt' => HeroImageRequirements::MISSING_ALT]);

        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
    }

    public function test_staff_publish_succeeds_with_alt_text_or_without_a_hero(): void
    {
        $admin = User::factory()->admin()->create();
        $withAlt = $this->postWithHero('A rescued tortoise');
        $noHero = Post::factory()->create(['hero_image' => null, 'hero_image_alt' => null]);

        $this->actingAs($admin)->post("/staff/posts/{$withAlt->id}/publish")->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/staff/posts/{$noHero->id}/publish")->assertSessionHasNoErrors();

        $this->assertSame(PostStatus::Published, $withAlt->fresh()->status);
        $this->assertSame(PostStatus::Published, $noHero->fresh()->status);
    }

    public function test_scheduling_is_blocked_without_hero_alt_text(): void
    {
        $admin = User::factory()->admin()->create();
        $post = $this->postWithHero('   ');

        $this->actingAs($admin)
            ->post("/staff/posts/{$post->id}/schedule", ['scheduled_for' => now()->addDay()->toDateTimeString()])
            ->assertSessionHasErrors(['hero_image_alt' => HeroImageRequirements::MISSING_ALT]);

        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
    }

    public function test_admin_api_writer_publish_is_blocked_without_hero_alt_text(): void
    {
        $admin = User::factory()->admin()->create();
        $post = $this->postWithHero(null);

        try {
            app(EditorialPostWriter::class)->publish($admin, $post);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertSame([HeroImageRequirements::MISSING_ALT], $e->errors()['hero_image_alt']);
        }

        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
    }

    public function test_scheduled_publisher_skips_posts_without_hero_alt_text(): void
    {
        User::factory()->superAdmin()->create();
        $blocked = $this->postWithHero('', ['status' => PostStatus::Scheduled, 'scheduled_for' => now()->subMinute()]);
        $ready = $this->postWithHero('Volunteers at the shelter', ['status' => PostStatus::Scheduled, 'scheduled_for' => now()->subMinute()]);

        $this->artisan('posts:publish-scheduled')
            ->expectsOutputToContain('Skipped (hero image has no alt text): '.$blocked->title)
            ->assertSuccessful();

        $this->assertSame(PostStatus::Scheduled, $blocked->fresh()->status);
        $this->assertSame(PostStatus::Published, $ready->fresh()->status);
    }

    public function test_page_publish_is_blocked_without_hero_alt_text(): void
    {
        $staff = User::factory()->staff()->create();
        $page = Page::factory()->create([
            'author_id' => $staff->id,
            'hero_image' => 'https://images.example.test/about.jpg',
            'hero_image_alt' => '',
        ]);

        $this->actingAs($staff)
            ->post("/staff/pages/{$page->id}/publish")
            ->assertSessionHasErrors(['hero_image_alt' => HeroImageRequirements::MISSING_ALT]);

        $this->assertNotSame(PostStatus::Published, $page->fresh()->status);

        $page->update(['hero_image_alt' => 'The APES rescue centre entrance']);
        $this->actingAs($staff)->post("/staff/pages/{$page->id}/publish")->assertSessionHasNoErrors();
        $this->assertSame(PostStatus::Published, $page->fresh()->status);
    }
}
