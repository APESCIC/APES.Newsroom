<?php

namespace App\Services\Media;

use App\Enums\MediaAssetStatus;
use App\Jobs\ProcessMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\User;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Validates and stores hero images (#533).
 *
 * The file type is sniffed from the bytes, never trusted from the name or
 * the browser. The kept original is re-encoded through GD so EXIF location
 * and camera data never reach the public disk.
 */
class HeroImageStore
{
    public function __construct(private readonly ImageCodec $codec) {}

    public function storeUpload(UploadedFile $file, ?User $uploader): MediaAsset
    {
        $bytes = (string) file_get_contents((string) $file->getRealPath());

        return $this->storeBytes(
            $bytes,
            (string) $file->getClientOriginalName(),
            $uploader?->id,
        );
    }

    public function storeBytes(
        string $bytes,
        ?string $originalName,
        ?int $uploaderId,
        string $source = MediaAsset::SOURCE_UPLOAD,
        ?string $sourceUrl = null,
    ): MediaAsset {
        $this->assertSize($bytes);

        $sha256 = hash('sha256', $bytes);
        $existing = MediaAsset::query()->where('sha256', $sha256)->first();
        if ($existing) {
            return $existing;
        }

        $mime = $this->sniffMime($bytes);
        $this->assertDimensions($bytes);

        $this->codec->raiseMemoryLimit();
        $image = $this->codec->orient($this->codec->decode($bytes), $bytes, $mime);
        $original = $this->codec->encode($image, $mime, (int) config('newsroom.hero_uploads.quality.original', 90));
        $width = imagesx($image);
        $height = imagesy($image);
        unset($image);

        $uuid = (string) Str::uuid();
        $disk = (string) config('newsroom.hero_uploads.disk', 'public');
        $path = sprintf(
            '%s/%s/%s/%s/original.%s',
            trim((string) config('newsroom.hero_uploads.path_prefix', 'media'), '/'),
            now()->format('Y'),
            now()->format('m'),
            $uuid,
            ImageCodec::EXTENSIONS[$mime],
        );

        Storage::disk($disk)->put($path, $original);

        $asset = MediaAsset::create([
            'uuid' => $uuid,
            'disk' => $disk,
            'path' => $path,
            'mime' => $mime,
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($original),
            'original_name' => $this->cleanName($originalName),
            'status' => MediaAssetStatus::Pending,
            'variants' => ['widths' => [], 'social' => []],
            'source' => $source,
            'source_url' => $sourceUrl,
            'sha256' => $sha256,
            'uploaded_by' => $uploaderId,
        ]);

        ProcessMediaAssetJob::dispatch($asset->id)->afterCommit();

        return $asset->refresh();
    }

    public static function maxSizeLabel(): string
    {
        $maxKb = (int) config('newsroom.hero_uploads.max_kb', 10240);

        return $maxKb >= 1024 ? round($maxKb / 1024, 1).' MB' : $maxKb.' KB';
    }

    public static function tooLargeMessage(): string
    {
        return 'The image must be '.self::maxSizeLabel().' or smaller.';
    }

    private function assertSize(string $bytes): void
    {
        if ($bytes === '') {
            throw new InvalidImageException('The file is empty.');
        }

        if (strlen($bytes) > (int) config('newsroom.hero_uploads.max_kb', 10240) * 1024) {
            throw new InvalidImageException(self::tooLargeMessage());
        }
    }

    private function sniffMime(string $bytes): string
    {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if ($mime === 'image/svg+xml' || str_contains(strtolower(substr($bytes, 0, 1024)), '<svg')) {
            throw new InvalidImageException('SVG images are not accepted. Upload a JPEG, PNG, WebP or AVIF image.');
        }

        if (! array_key_exists($mime, ImageCodec::EXTENSIONS)) {
            throw new InvalidImageException('This file is not a supported image. Upload a JPEG, PNG, WebP or AVIF image.');
        }

        if (! $this->codec->supports($mime)) {
            throw new InvalidImageException(
                $mime === 'image/avif'
                    ? 'AVIF images are not supported on this server. Upload a JPEG, PNG or WebP image.'
                    : 'This image type is not supported on this server.'
            );
        }

        return $mime;
    }

    private function assertDimensions(string $bytes): void
    {
        $size = @getimagesizefromstring($bytes);
        if (! is_array($size) || $size[0] < 1 || $size[1] < 1) {
            throw new InvalidImageException('This image could not be read. It may be damaged; try exporting it again.');
        }

        $maxPixels = (int) config('newsroom.hero_uploads.max_pixels', 40_000_000);
        if ($maxPixels < $size[0] * $size[1]) {
            throw new InvalidImageException(sprintf(
                'This image is too large (%d x %d pixels). Resize it to under %d megapixels and try again.',
                $size[0],
                $size[1],
                (int) floor($maxPixels / 1_000_000),
            ));
        }
    }

    private function cleanName(?string $name): ?string
    {
        $base = trim(strip_tags(pathinfo((string) $name, PATHINFO_FILENAME)));

        return $base !== '' ? Str::limit($base, 200, '') : null;
    }
}
