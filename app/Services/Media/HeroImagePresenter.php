<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Post;

/**
 * Public hero image fields for posts and pages (#534, #536).
 *
 * Hero URLs that still carry Ghost's `__GHOST_URL__` placeholder never
 * resolve, so they are presented as "no hero" rather than a broken image.
 */
class HeroImagePresenter
{
    /**
     * @return array{
     *     hero_image: ?string,
     *     hero_image_srcset: ?array{webp: string, jpeg: string},
     *     hero_image_width: ?int,
     *     hero_image_height: ?int,
     *     hero_image_focal: ?array{x: float, y: float},
     *     hero_image_social: ?string
     * }
     */
    public function present(Post|Page $model): array
    {
        $url = $this->usableUrl($model->hero_image);
        if ($url === null) {
            return [
                'hero_image' => null,
                'hero_image_srcset' => null,
                'hero_image_width' => null,
                'hero_image_height' => null,
                'hero_image_focal' => null,
                'hero_image_social' => null,
            ];
        }

        $focal = $this->focal($model->hero_focal_x, $model->hero_focal_y);
        $asset = $model->hero_media_id !== null ? $model->heroMedia : null;

        if (! $asset instanceof MediaAsset || ! $asset->isReady() || $asset->widthVariants() === []) {
            return [
                'hero_image' => $url,
                'hero_image_srcset' => null,
                'hero_image_width' => $asset?->width,
                'hero_image_height' => $asset?->height,
                'hero_image_focal' => $focal,
                'hero_image_social' => $url,
            ];
        }

        $variants = $asset->widthVariants();
        $largest = $variants[count($variants) - 1];
        $social = $asset->socialPath($focal['x'] ?? null, $focal['y'] ?? null);

        return [
            'hero_image' => MediaAsset::publicUrl($largest['jpeg']),
            'hero_image_srcset' => [
                'webp' => $this->srcset($variants, 'webp'),
                'jpeg' => $this->srcset($variants, 'jpeg'),
            ],
            'hero_image_width' => $largest['width'],
            'hero_image_height' => $largest['height'],
            'hero_image_focal' => $focal,
            'hero_image_social' => $social !== null ? MediaAsset::publicUrl($social) : MediaAsset::publicUrl($largest['jpeg']),
        ];
    }

    public function usableUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '' || str_contains($trimmed, '__GHOST_URL__')) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @return array{x: float, y: float}|null
     */
    private function focal(mixed $x, mixed $y): ?array
    {
        if ($x === null || $y === null) {
            return null;
        }

        return [
            'x' => round(max(0.0, min(1.0, (float) $x)), 4),
            'y' => round(max(0.0, min(1.0, (float) $y)), 4),
        ];
    }

    /**
     * @param  list<array{width: int, height: int, webp: string, jpeg: string}>  $variants
     */
    private function srcset(array $variants, string $format): string
    {
        return implode(', ', array_map(
            fn (array $variant) => MediaAsset::publicUrl($variant[$format]).' '.$variant['width'].'w',
            $variants,
        ));
    }
}
