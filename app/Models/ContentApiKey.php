<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A named Content API key. Only a SHA-256 hash and a short prefix are
 * stored; the plain key is shown once when it is created.
 */
#[Fillable(['name', 'key_hash', 'prefix', 'created_by', 'last_used_at', 'revoked_at'])]
class ContentApiKey extends Model
{
    public const PREFIX = 'nr_content_';

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<ContentApiKey>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * @return array{key: string, model: self}
     */
    public static function issue(string $name, ?User $creator): array
    {
        $plain = self::PREFIX.Str::random(40);

        $model = static::query()->create([
            'name' => $name,
            'key_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 15),
            'created_by' => $creator?->id,
        ]);

        return ['key' => $plain, 'model' => $model];
    }

    public static function findActive(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::query()->active()->where('key_hash', hash('sha256', $plain))->first();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
