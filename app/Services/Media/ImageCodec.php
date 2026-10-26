<?php

namespace App\Services\Media;

use GdImage;
use RuntimeException;

/**
 * Thin GD wrapper for decoding, re-encoding, resizing and cropping images.
 *
 * Every write goes through GD, so the output never carries the source file's
 * EXIF, XMP or ICC metadata.
 */
class ImageCodec
{
    /** @var array<string, string> mime => extension */
    public const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public function supports(string $mime): bool
    {
        if (! function_exists('imagetypes')) {
            return false;
        }

        $flag = match ($mime) {
            'image/jpeg' => IMG_JPG,
            'image/png' => IMG_PNG,
            'image/webp' => IMG_WEBP,
            'image/avif' => defined('IMG_AVIF') ? IMG_AVIF : 0,
            default => 0,
        };

        return $flag !== 0 && (imagetypes() & $flag) !== 0;
    }

    /**
     * Decoded GD images take roughly 5 bytes per pixel, so a 40 MP photo
     * needs far more than PHP's default 128M.
     */
    public function raiseMemoryLimit(): void
    {
        $current = ini_get('memory_limit');
        if ($current !== false && $current !== '-1' && $this->toBytes($current) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
    }

    public function decode(string $bytes): GdImage
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw new InvalidImageException('This image could not be read. It may be damaged; try exporting it again.');
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * Rotate or flip a JPEG so it displays upright once EXIF is gone.
     */
    public function orient(GdImage $image, string $bytes, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    public function encode(GdImage $image, string $mime, int $quality): string
    {
        ob_start();
        try {
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($this->flatten($image), null, $quality),
                'image/png' => imagepng($image, null, 6),
                'image/webp' => imagewebp($image, null, $quality),
                'image/avif' => imageavif($image, null, $quality, 6),
                default => throw new RuntimeException("Unsupported output type {$mime}."),
            };
            $bytes = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (! $ok || $bytes === '') {
            throw new RuntimeException("Could not encode image as {$mime}.");
        }

        return $bytes;
    }

    public function resize(GdImage $image, int $width, int $height): GdImage
    {
        $target = $this->canvas($width, $height);
        imagecopyresampled($target, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

        return $target;
    }

    /**
     * Crop to fill the target box, keeping the focal point (0-1 on each axis)
     * as close to the centre as the image edges allow.
     */
    public function cover(GdImage $image, int $width, int $height, float $focalX, float $focalY): GdImage
    {
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        $scale = max($width / $sourceWidth, $height / $sourceHeight);

        $cropWidth = (int) min($sourceWidth, round($width / $scale));
        $cropHeight = (int) min($sourceHeight, round($height / $scale));

        $left = (int) round($focalX * $sourceWidth - $cropWidth / 2);
        $top = (int) round($focalY * $sourceHeight - $cropHeight / 2);
        $left = max(0, min($sourceWidth - $cropWidth, $left));
        $top = max(0, min($sourceHeight - $cropHeight, $top));

        $target = $this->canvas($width, $height);
        imagecopyresampled($target, $image, 0, 0, $left, $top, $width, $height, $cropWidth, $cropHeight);

        return $target;
    }

    private function toBytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function canvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        return $canvas;
    }

    /**
     * JPEG has no alpha channel, so composite transparent pixels onto white.
     */
    private function flatten(GdImage $image): GdImage
    {
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $flat;
    }
}
