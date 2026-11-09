<?php

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\Storage;

/**
 * Draws plain placeholder PNGs for the demo media library. Files go to the
 * public disk under demo/ (git-ignored) and are rewritten on every run.
 */
final class PlaceholderImages
{
    public const DIRECTORY = 'demo';

    public const WIDTH = 1200;

    public const HEIGHT = 675;

    /**
     * Soft palette in the Newsroom's teal and green range.
     *
     * @var list<array{0: int, 1: int, 2: int}>
     */
    private const COLOURS = [
        [14, 116, 111], [21, 128, 61], [59, 130, 146], [101, 163, 13],
        [8, 145, 178], [13, 148, 136], [77, 124, 15], [30, 64, 175],
    ];

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    public static function clear(): void
    {
        Storage::disk('public')->deleteDirectory(self::DIRECTORY);
    }

    /**
     * @return array{path: string, bytes: int, sha256: string}
     */
    public static function make(int $index, string $label): array
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        [$r, $g, $b] = self::COLOURS[$index % count(self::COLOURS)];
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        $light = imagecolorallocate($image, 255, 255, 255);
        $faint = imagecolorallocatealpha($image, 255, 255, 255, 100);
        imagefilledellipse($image, 980, 140, 420, 420, $faint);
        imagefilledellipse($image, 180, 600, 360, 360, $faint);

        $text = 'Demo image '.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
        imagestring($image, 5, 60, 290, $text, $light);
        imagestring($image, 5, 60, 320, substr($label, 0, 90), $light);
        imagestring($image, 3, 60, 360, 'Placeholder for local testing only', $light);

        ob_start();
        imagepng($image, null, 6);
        $bytes = (string) ob_get_clean();

        $path = self::DIRECTORY.'/demo-image-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'.png';
        Storage::disk('public')->put($path, $bytes);

        return ['path' => $path, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
}
