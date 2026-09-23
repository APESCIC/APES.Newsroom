<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'membership_plan_id',
    'status',
    'interval',
    'stripe_customer_id',
    'stripe_subscription_id',
    'current_period_end',
])]
class Membership extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'current_period_end' => 'datetime',
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
     * @return BelongsTo<MembershipPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function isPaying(): bool
    {
        return $this->status->isPaying();
    }
}
