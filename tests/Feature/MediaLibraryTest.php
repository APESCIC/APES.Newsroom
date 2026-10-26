<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\HeroImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesTestImages;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use CreatesTestImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function asset(string $name, int $size = 120): MediaAsset
    {
        return app(HeroImageStore::class)->storeBytes($this->jpegBytes($size, $size), $name.'.jpg', null)->fresh();
    }

    public function test_library_is_staff_only(): void
    {
        $this->get('/staff/media')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/staff/media')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/staff/media/library')->assertForbidden();

        $this->actingAs(User::factory()->staff()->create())->get('/staff/media')->assertOk();
    }

    public function test_library_is_paginated_newest_first(): void
    {
        foreach (range(1, 26) as $i) {
            $this->asset("photo-{$i}", 20 + $i);
        }
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/staff/media')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Media/Index')
                ->has('assets.data', 24)
                ->where('assets.total', 26)
                ->where('assets.last_page', 2)
                ->where('assets.data.0.original_name', 'photo-26'));

        $this->actingAs($staff)->getJson('/staff/media/library?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.original_name', 'photo-1');
    }

    public function test_library_search_matches_file_name_and_alt_text(): void
    {
        $this->asset('meerkat-family');
        $tortoise = $this->asset('IMG_2041');
        $tortoise->update(['alt' => 'Leopard tortoise basking']);
        $this->asset('shelter-front');
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->getJson('/staff/media/library?q=meerkat')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'meerkat-family');

        $this->actingAs($staff)->get('/staff/media?q=tortoise')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('assets.data', 1)
                ->where('assets.data.0.id', $tortoise->id)
                ->where('search', 'tortoise'));
    }

    public function test_usage_lists_posts_and_pages_using_the_image(): void
    {
        $asset = $this->asset('hero');
        $hero = Post::factory()->create(['title' => 'Hero user', 'hero_image' => $asset->url(), 'hero_media_id' => $asset->id]);
        Page::factory()->create([
            'title' => 'Body user',
            'content' => ['blocks' => [['type' => 'image', 'data' => ['file' => ['url' => MediaAsset::publicUrl($asset->widthVariants()[0]['jpeg'])], 'alt' => 'x']]]],
        ]);
        Post::factory()->create(['title' => 'Unrelated']);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->getJson("/staff/media/{$asset->id}/usage")
            ->assertOk()
            ->assertJsonCount(2, 'usage')
            ->assertJsonFragment(['type' => 'post', 'id' => $hero->id, 'title' => 'Hero user', 'as_hero' => true, 'in_body' => false])
            ->assertJsonFragment(['type' => 'page', 'title' => 'Body user', 'as_hero' => false, 'in_body' => true]);

        $this->actingAs($staff)->get('/staff/media')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('assets.data.0.usage_count', 2));
    }

    public function test_image_in_use_cannot_be_deleted_without_confirmation(): void
    {
        $asset = $this->asset('in-use');
        Post::factory()->create(['title' => 'Rescue update', 'hero_image' => $asset->url(), 'hero_media_id' => $asset->id]);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->delete("/staff/media/{$asset->id}")
            ->assertSessionHasErrors(['confirm' => 'This image is used by: Rescue update. Confirm to delete it anyway.']);

        $this->assertModelExists($asset);
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_confirmed_delete_removes_files_clears_heroes_and_is_audited(): void
    {
        $asset = $this->asset('to-delete');
        $post = Post::factory()->create([
            'hero_image' => $asset->url(),
            'hero_image_alt' => 'Old hero',
            'hero_media_id' => $asset->id,
            'hero_focal_x' => 0.2,
            'hero_focal_y' => 0.2,
        ]);
        $staff = User::factory()->staff()->create();
        $paths = [$asset->path, $asset->widthVariants()[0]['webp']];

        $this->actingAs($staff)->delete("/staff/media/{$asset->id}", ['confirm' => true])
            ->assertRedirect('/staff/media')
            ->assertSessionHas('status', 'Image deleted.');

        $this->assertModelMissing($asset);
        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $post->refresh();
        $this->assertNull($post->hero_image);
        $this->assertNull($post->hero_media_id);
        $this->assertNull($post->hero_focal_x);

        $log = AuditLog::query()->where('action', 'media.deleted')->sole();
        $this->assertSame($staff->id, $log->actor_id);
        $this->assertSame($post->id, $log->payload['used_by'][0]['id']);
    }

    public function test_unused_image_deletes_without_confirmation(): void
    {
        $asset = $this->asset('unused');
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->delete("/staff/media/{$asset->id}")->assertRedirect('/staff/media');

        $this->assertModelMissing($asset);
    }

    public function test_public_users_cannot_delete_or_read_usage(): void
    {
        $asset = $this->asset('protected');
        $user = User::factory()->create();

        $this->actingAs($user)->delete("/staff/media/{$asset->id}", ['confirm' => true])->assertForbidden();
        $this->actingAs($user)->getJson("/staff/media/{$asset->id}/usage")->assertForbidden();
        $this->assertModelExists($asset);
    }
}
