<?php

namespace Tests\Feature;

use App\Jobs\GenerateSocialCropJob;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\HeroImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesTestImages;
use Tests\TestCase;

class HeroImagePayloadTest extends TestCase
{
    use CreatesTestImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function asset(): MediaAsset
    {
        return app(HeroImageStore::class)->storeBytes($this->jpegBytes(1600, 900), 'hero.jpg', null)->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function postPayload(array $overrides = []): array
    {
        return [
            'title' => 'Hero payload',
            'excerpt' => 'Excerpt',
            'channel' => 'apes_cic',
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]]],
            ...$overrides,
        ];
    }

    public function test_saving_an_uploaded_hero_stores_the_asset_url_and_focal_point(): void
    {
        $staff = User::factory()->staff()->create();
        $asset = $this->asset();

        $this->actingAs($staff)->post('/staff/posts', $this->postPayload([
            'hero_image' => 'https://evil.example.test/swap.jpg',
            'hero_image_alt' => 'Meerkats on watch',
            'hero_media_id' => $asset->id,
            'hero_focal_x' => 0.2,
            'hero_focal_y' => 0.8,
        ]))->assertRedirect();

        $post = Post::query()->where('title', 'Hero payload')->sole();
        $this->assertSame($asset->id, $post->hero_media_id);
        $this->assertSame($asset->url(), $post->hero_image);
        $this->assertSame(0.2, $post->hero_focal_x);
        $this->assertSame(0.8, $post->hero_focal_y);

        $this->assertTrue($asset->fresh()->hasSocialCrop(0.2, 0.8));
    }

    public function test_focal_point_change_queues_a_social_crop(): void
    {
        $staff = User::factory()->staff()->create();
        $asset = $this->asset();
        $post = Post::factory()->create([
            'author_id' => $staff->id,
            'hero_image' => $asset->url(),
            'hero_image_alt' => 'Alt',
            'hero_media_id' => $asset->id,
        ]);

        Queue::fake();
        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", $this->postPayload([
            'slug' => $post->slug,
            'hero_image' => $asset->url(),
            'hero_media_id' => $asset->id,
            'hero_focal_x' => 0.9,
            'hero_focal_y' => 0.1,
        ]))->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateSocialCropJob::class, fn (GenerateSocialCropJob $job) => $job->assetId === $asset->id
            && $job->focalX === 0.9
            && $job->focalY === 0.1);
    }

    public function test_focal_point_must_be_between_zero_and_one_and_media_must_exist(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/posts', $this->postPayload([
            'hero_media_id' => 99999,
            'hero_focal_x' => 1.5,
            'hero_focal_y' => -0.1,
        ]))->assertSessionHasErrors(['hero_media_id', 'hero_focal_x', 'hero_focal_y']);
    }

    public function test_clearing_the_hero_url_clears_media_and_focal_point(): void
    {
        $staff = User::factory()->staff()->create();
        $asset = $this->asset();
        $post = Post::factory()->create([
            'author_id' => $staff->id,
            'hero_image' => $asset->url(),
            'hero_media_id' => $asset->id,
            'hero_focal_x' => 0.3,
            'hero_focal_y' => 0.3,
        ]);

        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", $this->postPayload([
            'slug' => $post->slug,
            'hero_image' => '',
            'hero_media_id' => $asset->id,
        ]))->assertSessionHasNoErrors();

        $post->refresh();
        $this->assertNull($post->hero_image);
        $this->assertNull($post->hero_media_id);
        $this->assertNull($post->hero_focal_x);
    }

    public function test_post_editor_receives_media_and_upload_limits(): void
    {
        $staff = User::factory()->staff()->create();
        $asset = $this->asset();
        $post = Post::factory()->create([
            'author_id' => $staff->id,
            'hero_image' => $asset->url(),
            'hero_media_id' => $asset->id,
            'hero_focal_x' => 0.4,
            'hero_focal_y' => 0.6,
        ]);

        $this->actingAs($staff)->get("/staff/posts/{$post->id}/edit")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('post.hero_media_id', $asset->id)
                ->where('post.hero_focal_x', 0.4)
                ->where('post.hero_media.status', 'ready')
                ->where('heroUpload.max_kb', 10240)
                ->where('heroUpload.types', fn ($types) => collect($types)->contains('image/webp'))
            );
    }

    public function test_pages_accept_uploaded_heroes_and_focal_points(): void
    {
        $staff = User::factory()->staff()->create();
        $asset = $this->asset();

        $this->actingAs($staff)->post('/staff/pages', [
            'title' => 'About the rescue',
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]]],
            'hero_image_alt' => 'The rescue centre',
            'hero_media_id' => $asset->id,
            'hero_focal_x' => 0.5,
            'hero_focal_y' => 0.25,
        ])->assertRedirect();

        $page = Page::query()->where('title', 'About the rescue')->sole();
        $this->assertSame($asset->url(), $page->hero_image);
        $this->assertSame(0.25, $page->hero_focal_y);

        $this->actingAs($staff)->get("/staff/pages/{$page->id}/edit")
            ->assertInertia(fn (AssertableInertia $inertia) => $inertia
                ->where('page.hero_media_id', $asset->id)
                ->has('heroUpload.types'));
    }
}
