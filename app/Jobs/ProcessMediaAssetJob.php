<?php

namespace App\Jobs;

use App\Enums\MediaAssetStatus;
use App\Models\MediaAsset;
use App\Services\Media\MediaVariantGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generates responsive widths and the default social crop for an asset (#534).
 *
 * Failures are recorded on the asset so the editor can show them; the job
 * is not retried because a broken image fails the same way every time.
 */
class ProcessMediaAssetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $assetId) {}

    public function handle(MediaVariantGenerator $generator): void
    {
        $asset = MediaAsset::query()->find($this->assetId);
        if (! $asset) {
            return;
        }

        try {
            $generator->generateWidths($asset);
            $generator->generateSocialCrop($asset, 0.5, 0.5);

            $asset->refresh();
            $asset->update([
                'status' => MediaAssetStatus::Ready,
                'failure_reason' => null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Media asset processing failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            $asset->update([
                'status' => MediaAssetStatus::Failed,
                'failure_reason' => Str::limit('Processing failed: '.$e->getMessage(), 480),
            ]);
        }
    }
}
