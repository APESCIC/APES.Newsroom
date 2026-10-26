<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Writes responsive widths and social crops next to a stored original (#534).
 */
class MediaVariantGenerator
{
    public function __construct(private readonly ImageCodec $codec) {}

    /**
     * Widths never exceed the original. An original narrower than the largest
     * configured width is also kept at its own width so it is still offered.
     *
     * @return list<int>
     */
    public function targetWidths(int $originalWidth): array
    {
        $configured = array_map('intval', (array) config('newsroom.hero_uploads.widths', [640, 1200, 2000]));
        sort($configured);

        $targets = array_values(array_filter($configured, fn (int $width) => $width < $originalWidth));
        if ($originalWidth <= max($configured)) {
            $targets[] = $originalWidth;
        }

        return array_values(array_unique($targets));
    }

    public function generateWidths(MediaAsset $asset): void
    {
        $image = $this->load($asset);
        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);
        $quality = (array) config('newsroom.hero_uploads.quality', []);

        $widths = [];
        foreach ($this->targetWidths($originalWidth) as $width) {
            $height = max(1, (int) round($originalHeight * $width / $originalWidth));
            $resized = $width === $originalWidth ? $image : $this->codec->resize($image, $width, $height);

            $webp = $asset->directory()."/w{$width}.webp";
            $jpeg = $asset->directory()."/w{$width}.jpg";
            $this->put($asset, $webp, $this->codec->encode($resized, 'image/webp', (int) ($quality['webp'] ?? 80)));
            $this->put($asset, $jpeg, $this->codec->encode($resized, 'image/jpeg', (int) ($quality['jpeg'] ?? 82)));

            $widths[] = ['width' => $width, 'height' => $height, 'webp' => $webp, 'jpeg' => $jpeg];
        }

        $variants = $asset->variants ?? [];
        $variants['widths'] = $widths;
        $asset->variants = $variants;
        $asset->save();
    }

    public function generateSocialCrop(MediaAsset $asset, ?float $focalX, ?float $focalY): string
    {
        $x = $this->clamp($focalX);
        $y = $this->clamp($focalY);
        $key = MediaAsset::focalKey($x, $y);

        $size = (array) config('newsroom.hero_uploads.social', ['width' => 1200, 'height' => 630]);
        $crop = $this->codec->cover($this->load($asset), (int) $size['width'], (int) $size['height'], $x, $y);
        $path = $asset->directory()."/social-{$key}.jpg";
        $quality = (int) config('newsroom.hero_uploads.quality.jpeg', 82);
        $this->put($asset, $path, $this->codec->encode($crop, 'image/jpeg', $quality));

        $fresh = $asset->fresh() ?? $asset;
        $variants = $fresh->variants ?? [];
        $variants['social'] = [...($variants['social'] ?? []), $key => $path];
        $fresh->variants = $variants;
        $fresh->save();
        $asset->setRawAttributes($fresh->getAttributes(), true);

        return $path;
    }

    /**
     * @return list<string>
     */
    public function allPaths(MediaAsset $asset): array
    {
        $paths = [$asset->path];
        foreach ($asset->widthVariants() as $variant) {
            $paths[] = $variant['webp'];
            $paths[] = $variant['jpeg'];
        }

        foreach ((array) ($asset->variants['social'] ?? []) as $path) {
            $paths[] = (string) $path;
        }

        return array_values(array_unique($paths));
    }

    private function load(MediaAsset $asset): GdImage
    {
        $bytes = Storage::disk($asset->disk)->get($asset->path);
        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The original file is missing from storage.');
        }

        $this->codec->raiseMemoryLimit();

        return $this->codec->decode($bytes);
    }

    private function put(MediaAsset $asset, string $path, string $bytes): void
    {
        if (! Storage::disk($asset->disk)->put($path, $bytes)) {
            throw new RuntimeException("Could not write {$path}.");
        }
    }

    private function clamp(?float $value): float
    {
        return max(0.0, min(1.0, $value ?? 0.5));
    }
}
