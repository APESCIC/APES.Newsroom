<?php

namespace App\Models;

use App\Enums\MediaAssetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An uploaded or imported image stored on the public disk (#532).
 *
 * `variants` holds the generated files:
 *   widths: list of {width, height, webp, jpeg} relative paths, smallest first
 *   social: map of focal key (e.g. "x50y50") to a 1200x630 JPEG path
 */
#[Fillable([
    'uuid', 'disk', 'path', 'mime', 'width', 'height', 'bytes', 'original_name', 'alt',
    'status', 'failure_reason', 'variants', 'source', 'source_url', 'sha256', 'uploaded_by',
])]
class MediaAsset extends Model
{
    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_GHOST_IMPORT = 'ghost_import';

    protected function casts(): array
    {
        return [
            'status' => MediaAssetStatus::class,
            'variants' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isReady(): bool
    {
        return $this->status === MediaAssetStatus::Ready;
    }

    public function directory(): string
    {
        return dirname($this->path);
    }

    public function url(): string
    {
        return self::publicUrl($this->path);
    }

    public static function publicUrl(string $relativePath): string
    {
        return url('storage/'.ltrim($relativePath, '/'));
    }

    /**
     * @return list<array{width: int, height: int, webp: string, jpeg: string}>
     */
    public function widthVariants(): array
    {
        $widths = $this->variants['widths'] ?? [];

        return is_array($widths) ? array_values($widths) : [];
    }

    public static function focalKey(?float $x, ?float $y): string
    {
        return sprintf('x%dy%d', (int) round(($x ?? 0.5) * 100), (int) round(($y ?? 0.5) * 100));
    }

    public function socialPath(?float $x, ?float $y): ?string
    {
        $social = $this->variants['social'] ?? [];
        if (! is_array($social)) {
            return null;
        }

        return $social[self::focalKey($x, $y)] ?? $social[self::focalKey(0.5, 0.5)] ?? null;
    }

    public function hasSocialCrop(?float $x, ?float $y): bool
    {
        $social = $this->variants['social'] ?? [];

        return is_array($social) && isset($social[self::focalKey($x, $y)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function editorPayload(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url(),
            'status' => $this->status->value,
            'failure_reason' => $this->failure_reason,
            'width' => $this->width,
            'height' => $this->height,
            'mime' => $this->mime,
            'original_name' => $this->original_name,
            'alt' => $this->alt,
            'thumbnail_url' => $this->thumbnailUrl(),
        ];
    }

    public function thumbnailUrl(): string
    {
        $smallest = $this->widthVariants()[0] ?? null;

        return $smallest !== null ? self::publicUrl($smallest['jpeg']) : $this->url();
    }
}
