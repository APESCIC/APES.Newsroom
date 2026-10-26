<?php

namespace App\Services\Media;

use App\Jobs\GenerateSocialCropJob;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Validation\Rule;

/**
 * Editor and API input for uploaded hero images and focal points (#535, #536).
 */
class HeroImageInput
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'hero_media_id' => ['nullable', 'integer', Rule::exists('media_assets', 'id')],
            'hero_focal_x' => ['nullable', 'numeric', 'between:0,1'],
            'hero_focal_y' => ['nullable', 'numeric', 'between:0,1'],
        ];
    }

    /**
     * An uploaded asset owns the hero URL, so the stored URL always matches
     * the asset. Clearing the URL clears the asset and focal point too.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalize(array $validated): array
    {
        if (array_key_exists('hero_image', $validated) && blank($validated['hero_image'])) {
            $validated['hero_media_id'] = null;
            $validated['hero_focal_x'] = null;
            $validated['hero_focal_y'] = null;

            return $validated;
        }

        if (array_key_exists('hero_media_id', $validated) && $validated['hero_media_id'] !== null) {
            $asset = MediaAsset::query()->find($validated['hero_media_id']);
            if ($asset) {
                $validated['hero_image'] = $asset->url();
            }
        }

        return $validated;
    }

    public function afterSave(Post|Page $model): void
    {
        if ($model->hero_media_id === null || $model->hero_focal_x === null || $model->hero_focal_y === null) {
            return;
        }

        $asset = $model->heroMedia()->first();
        if ($asset?->isReady() && ! $asset->hasSocialCrop($model->hero_focal_x, $model->hero_focal_y)) {
            GenerateSocialCropJob::dispatch($asset->id, $model->hero_focal_x, $model->hero_focal_y);
        }
    }

    /**
     * @return array{max_kb: int, max_label: string, types: list<string>}
     */
    public static function uploadLimits(): array
    {
        $codec = app(ImageCodec::class);

        return [
            'max_kb' => (int) config('newsroom.hero_uploads.max_kb', 10240),
            'max_label' => HeroImageStore::maxSizeLabel(),
            'types' => array_values(array_filter(
                array_keys(ImageCodec::EXTENSIONS),
                fn (string $mime) => $codec->supports($mime),
            )),
        ];
    }
}
