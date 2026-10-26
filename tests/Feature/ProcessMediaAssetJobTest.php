<?php

namespace Tests\Feature;

use App\Enums\MediaAssetStatus;
use App\Jobs\GenerateSocialCropJob;
use App\Jobs\ProcessMediaAssetJob;
use App\Services\Media\HeroImageStore;
use App\Services\Media\MediaVariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTestImages;
use Tests\TestCase;

class ProcessMediaAssetJobTest extends TestCase
{
    use CreatesTestImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_upload_queues_processing_and_leaves_the_asset_pending(): void
    {
        Queue::fake();

        $asset = app(HeroImageStore::class)->storeBytes($this->jpegBytes(900, 600), 'a.jpg', null);

        $this->assertSame(MediaAssetStatus::Pending, $asset->status);
        Queue::assertPushed(ProcessMediaAssetJob::class, fn (ProcessMediaAssetJob $job) => $job->assetId === $asset->id);
    }

    public function test_large_images_get_every_configured_width_in_webp_and_jpeg(): void
    {
        $asset = app(HeroImageStore::class)->storeBytes($this->jpegBytes(2500, 1250), 'wide.jpg', null)->fresh();

        $this->assertSame(MediaAssetStatus::Ready, $asset->status);
        $variants = $asset->widthVariants();
        $this->assertSame([640, 1200, 2000], array_column($variants, 'width'));
        $this->assertSame([320, 600, 1000], array_column($variants, 'height'));

        $disk = Storage::disk('public');
        foreach ($variants as $variant) {
            $this->assertSame('image/webp', getimagesizefromstring((string) $disk->get($variant['webp']))['mime']);
            $this->assertSame('image/jpeg', getimagesizefromstring((string) $disk->get($variant['jpeg']))['mime']);
        }

        $disk->assertExists($asset->path);
    }

    public function test_small_images_are_never_upscaled(): void
    {
        $asset = app(HeroImageStore::class)->storeBytes($this->jpegBytes(800, 400), 'small.jpg', null)->fresh();
        $this->assertSame([640, 800], array_column($asset->widthVariants(), 'width'));

        $tiny = app(HeroImageStore::class)->storeBytes($this->jpegBytes(300, 200), 'tiny.jpg', null)->fresh();
        $this->assertSame([300], array_column($tiny->widthVariants(), 'width'));
    }

    public function test_default_social_crop_is_1200_by_630(): void
    {
        $asset = app(HeroImageStore::class)->storeBytes($this->jpegBytes(1000, 1000), 'square.jpg', null)->fresh();

        $path = $asset->socialPath(0.5, 0.5);
        $this->assertNotNull($path);
        $size = getimagesizefromstring((string) Storage::disk('public')->get($path));
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
    }

    public function test_focal_point_social_crop_is_stored_per_focal_point(): void
    {
        $asset = app(HeroImageStore::class)->storeBytes($this->jpegBytes(1600, 1600), 'tall.jpg', null)->fresh();

        GenerateSocialCropJob::dispatchSync($asset->id, 0.25, 0.1);

        $asset->refresh();
        $this->assertTrue($asset->hasSocialCrop(0.25, 0.1));
        $this->assertStringEndsWith('social-x25y10.jpg', (string) $asset->socialPath(0.25, 0.1));
        $this->assertTrue($asset->hasSocialCrop(0.5, 0.5));
    }

    public function test_processing_failure_is_recorded_on_the_asset(): void
    {
        Queue::fake();
        $asset = app(HeroImageStore::class)->storeBytes($this->jpegBytes(200, 100), 'gone.jpg', null);
        Storage::disk('public')->delete($asset->path);

        (new ProcessMediaAssetJob($asset->id))->handle(app(MediaVariantGenerator::class));

        $asset->refresh();
        $this->assertSame(MediaAssetStatus::Failed, $asset->status);
        $this->assertStringContainsString('original file is missing', (string) $asset->failure_reason);
    }

    public function test_transparent_png_is_flattened_for_jpeg_variants(): void
    {
        $asset = app(HeroImageStore::class)->storeBytes($this->pngBytes(700, 300), 'logo.png', null)->fresh();

        $this->assertSame(MediaAssetStatus::Ready, $asset->status);
        $this->assertSame('image/png', $asset->mime);
        $jpeg = imagecreatefromstring((string) Storage::disk('public')->get($asset->widthVariants()[0]['jpeg']));
        $rgb = imagecolorsforindex($jpeg, imagecolorat($jpeg, 5, 5));
        $this->assertGreaterThan(240, $rgb['red']);
    }
}
