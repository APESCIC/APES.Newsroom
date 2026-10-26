<?php

namespace Tests\Feature;

use App\Enums\MediaAssetStatus;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestImages;
use Tests\TestCase;

class HeroImageUploadTest extends TestCase
{
    use CreatesTestImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_staff_can_upload_a_hero_image_and_it_is_processed(): void
    {
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($staff)->postJson('/staff/media/hero', [
            'file' => $this->uploadedFrom($this->jpegBytes(1500, 1000), 'Capuchin at dawn.jpg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('asset.status', 'ready')
            ->assertJsonPath('asset.width', 1500)
            ->assertJsonPath('asset.height', 1000)
            ->assertJsonPath('asset.mime', 'image/jpeg')
            ->assertJsonPath('asset.original_name', 'Capuchin at dawn');

        $asset = MediaAsset::query()->sole();
        $this->assertSame($staff->id, $asset->uploaded_by);
        $this->assertStringStartsWith('media/', $asset->path);
        $this->assertStringNotContainsString('Capuchin', $asset->path);
        Storage::disk('public')->assertExists($asset->path);
        $this->assertSame([640, 1200, 1500], array_column($asset->widthVariants(), 'width'));
    }

    public function test_guests_and_public_accounts_cannot_upload(): void
    {
        $file = $this->uploadedFrom($this->jpegBytes(40, 30), 'x.jpg');

        $this->postJson('/staff/media/hero', ['file' => $file])->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->postJson('/staff/media/hero', ['file' => $file])
            ->assertForbidden();

        $this->assertSame(0, MediaAsset::query()->count());
    }

    public function test_upload_route_is_csrf_protected_and_rate_limited(): void
    {
        $route = Route::getRoutes()->getByName('staff.media.hero.upload');
        $this->assertNotNull($route);
        $middleware = $route->gatherMiddleware();
        $this->assertContains('web', $middleware);
        $this->assertContains('throttle:media-upload', $middleware);

        config(['newsroom.hero_uploads.rate_per_minute' => 2]);
        $staff = User::factory()->staff()->create();

        foreach ([10, 11] as $size) {
            $this->actingAs($staff)->postJson('/staff/media/hero', [
                'file' => $this->uploadedFrom($this->jpegBytes($size, $size), 'a.jpg'),
            ])->assertCreated();
        }

        $this->actingAs($staff)->postJson('/staff/media/hero', [
            'file' => $this->uploadedFrom($this->jpegBytes(12, 12), 'a.jpg'),
        ])->assertStatus(429);
    }

    public function test_type_is_checked_from_content_not_the_file_name(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->postJson('/staff/media/hero', [
            'file' => $this->uploadedFrom('just some text, not an image', 'notes.jpg'),
        ])->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'This file is not a supported image. Upload a JPEG, PNG, WebP or AVIF image.');

        $this->actingAs($staff)->postJson('/staff/media/hero', [
            'file' => $this->uploadedFrom($this->pngBytes(64, 48), 'actually-a-png.jpg'),
        ])->assertCreated()
            ->assertJsonPath('asset.mime', 'image/png');

        $this->assertStringEndsWith('.png', MediaAsset::query()->sole()->path);
    }

    public function test_svg_is_rejected_even_with_an_image_extension(): void
    {
        $staff = User::factory()->staff()->create();
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="10" height="10"/></svg>';

        $this->actingAs($staff)->postJson('/staff/media/hero', [
            'file' => $this->uploadedFrom($svg, 'logo.png'),
        ])->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'SVG images are not accepted. Upload a JPEG, PNG, WebP or AVIF image.');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_oversize_upload_is_rejected_with_a_clear_message(): void
    {
        config(['newsroom.hero_uploads.max_kb' => 1]);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->postJson('/staff/media/hero', [
            'file' => $this->uploadedFrom($this->jpegBytes(600, 600), 'big.jpg'),
        ])->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'The image must be 1 KB or smaller.');
    }

    public function test_images_over_the_pixel_limit_are_rejected(): void
    {
        config(['newsroom.hero_uploads.max_pixels' => 1_000]);
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->postJson('/staff/media/hero', [
            'file' => $this->uploadedFrom($this->jpegBytes(100, 100), 'wide.jpg'),
        ])->assertStatus(422);

        $this->assertSame(0, MediaAsset::query()->count());
    }

    public function test_exif_location_and_camera_data_are_stripped_and_orientation_applied(): void
    {
        $staff = User::factory()->staff()->create();
        $source = $this->jpegWithExif(300, 200, orientation: 6);

        $sourceExif = exif_read_data('data://image/jpeg;base64,'.base64_encode($source));
        $this->assertSame('TestCam', $sourceExif['Make'] ?? null);
        $this->assertSame('N', $sourceExif['GPSLatitudeRef'] ?? null);

        $this->actingAs($staff)->postJson('/staff/media/hero', [
            'file' => $this->uploadedFrom($source, 'phone.jpg'),
        ])->assertCreated()
            ->assertJsonPath('asset.width', 200)
            ->assertJsonPath('asset.height', 300);

        $asset = MediaAsset::query()->sole();
        $stored = Storage::disk('public')->get($asset->path);
        $this->assertStringNotContainsString('TestCam', (string) $stored);
        $this->assertStringNotContainsString("Exif\0\0", (string) $stored);

        $storedExif = @exif_read_data('data://image/jpeg;base64,'.base64_encode((string) $stored));
        $this->assertArrayNotHasKey('Make', is_array($storedExif) ? $storedExif : []);
        $this->assertArrayNotHasKey('GPSLatitudeRef', is_array($storedExif) ? $storedExif : []);
    }

    public function test_uploading_the_same_image_twice_reuses_the_asset(): void
    {
        $staff = User::factory()->staff()->create();
        $bytes = $this->jpegBytes(50, 40);

        $first = $this->actingAs($staff)->postJson('/staff/media/hero', ['file' => $this->uploadedFrom($bytes, 'a.jpg')]);
        $second = $this->actingAs($staff)->postJson('/staff/media/hero', ['file' => $this->uploadedFrom($bytes, 'b.jpg')]);

        $first->assertCreated();
        $second->assertOk()->assertJsonPath('asset.id', $first->json('asset.id'));
        $this->assertSame(1, MediaAsset::query()->count());
    }

    public function test_staff_can_read_asset_status(): void
    {
        $staff = User::factory()->staff()->create();
        $id = $this->actingAs($staff)->postJson('/staff/media/hero', [
            'file' => $this->uploadedFrom($this->jpegBytes(80, 60), 'a.jpg'),
        ])->json('asset.id');

        MediaAsset::query()->whereKey($id)->update([
            'status' => MediaAssetStatus::Failed->value,
            'failure_reason' => 'Processing failed: boom',
        ]);

        $this->actingAs($staff)->getJson("/staff/media/{$id}")
            ->assertOk()
            ->assertJsonPath('asset.status', 'failed')
            ->assertJsonPath('asset.failure_reason', 'Processing failed: boom');

        $this->actingAs(User::factory()->create())->getJson("/staff/media/{$id}")->assertForbidden();
    }
}
