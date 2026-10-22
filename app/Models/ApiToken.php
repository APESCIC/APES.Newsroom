<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'name', 'token_hash', 'abilities', 'last_used_at', 'expires_at', 'revoked_at'])]
class ApiToken extends Model
{
    public const ABILITIES = [
        'posts:read' => 'Read posts',
        'posts:write' => 'Create and edit posts',
        'posts:publish' => 'Publish and unpublish posts',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  list<string>|null  $abilities  null grants every ability (tokens issued before abilities existed)
     * @return array{token: string, model: self}
     */
    public static function issue(User $user, string $name, ?array $abilities = null, ?Carbon $expiresAt = null): array
    {
        $plain = 'nr_admin_'.Str::random(48);

        $model = static::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'abilities' => $abilities === null ? null : array_values($abilities),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $plain, 'model' => $model];
    }

    public static function findByPlainToken(string $plain): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->first();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function hasAbility(string $ability): bool
    {
        return $this->abilities === null || in_array($ability, $this->abilities, true);
    }

    public function status(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            default => 'active',
        };
    }
}
