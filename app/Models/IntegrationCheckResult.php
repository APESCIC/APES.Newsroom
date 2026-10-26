<?php

namespace App\Models;

use App\Enums\IntegrationCheckStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['provider', 'status', 'message', 'duration_ms', 'trigger', 'user_id', 'checked_at'])]
class IntegrationCheckResult extends Model
{
    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    /** Checks of an unsaved value before it is stored; never counted as the provider's status. */
    public const TRIGGER_PRE_SAVE = 'pre_save';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => IntegrationCheckStatus::class,
            'duration_ms' => 'integer',
            'checked_at' => 'datetime',
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
     * Results that reflect the saved configuration.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfSavedConfiguration(Builder $query): void
    {
        $query->where('trigger', '!=', self::TRIGGER_PRE_SAVE);
    }

    public static function latestFor(string $provider): ?self
    {
        return self::query()
            ->where('provider', $provider)
            ->ofSavedConfiguration()
            ->latest('checked_at')
            ->latest('id')
            ->first();
    }
}
