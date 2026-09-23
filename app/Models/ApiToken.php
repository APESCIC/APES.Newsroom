<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'name', 'token_hash', 'last_used_at'])]
class ApiToken extends Model
{
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
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
     * @return array{token: string, model: self}
     */
    public static function issue(User $user, string $name): array
    {
        $plain = 'nr_admin_'.Str::random(48);

        $model = static::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
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
}
