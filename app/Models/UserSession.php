<?php

namespace App\Models;

use Database\Factories\UserSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed-in browser session. Live session data stays in the session
 * store (Redis on Cloudron); this row only lets users see and end them.
 */
class UserSession extends Model
{
    /** @use HasFactory<UserSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_key_hash',
        'ip_address',
        'user_agent',
        'browser',
        'platform',
        'device_type',
        'remembered',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'remembered' => 'boolean',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Sessions that have not been ended and could still be in use: seen
     * within the session lifetime, or remembered and seen within the
     * remember-me duration.
     *
     * @param  Builder<UserSession>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $sessionCutoff = now()->subMinutes((int) config('session.lifetime', 120) + 5);
        $rememberCutoff = now()->subMinutes((int) config('auth.guards.web.remember', 43200));

        $query->whereNull('revoked_at')
            ->where(fn (Builder $inner) => $inner
                ->where('last_seen_at', '>=', $sessionCutoff)
                ->orWhere(fn (Builder $remembered) => $remembered
                    ->where('remembered', true)
                    ->where('last_seen_at', '>=', $rememberCutoff)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
