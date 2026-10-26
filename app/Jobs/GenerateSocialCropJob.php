<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Services\Media\MediaVariantGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the 1200x630 social crop for a focal point chosen in the editor (#536).
 */
class GenerateSocialCropJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $assetId,
        public readonly float $focalX,
        public readonly float $focalY,
    ) {}

    public function handle(MediaVariantGenerator $generator): void
    {
        $asset = MediaAsset::query()->find($this->assetId);
        if (! $asset || ! $asset->isReady() || $asset->hasSocialCrop($this->focalX, $this->focalY)) {
            return;
        }

        try {
            $generator->generateSocialCrop($asset, $this->focalX, $this->focalY);
        } catch (Throwable $e) {
            Log::warning('Social crop generation failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
