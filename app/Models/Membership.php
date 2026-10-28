<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
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
#[Hidden(['staff_note'])]
class Membership extends Model
{
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'current_period_end' => 'datetime',
            'comped_at' => 'datetime',
            'staff_note' => 'encrypted',
            'staff_note_updated_at' => 'datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function compedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'comped_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function staffNoteUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_note_updated_by');
    }

    public function isPaying(): bool
    {
        return $this->status->isPaying();
    }

    public function isComped(): bool
    {
        return $this->comped_at !== null;
    }

    /**
     * Paid content access: an active Stripe subscription or complimentary
     * access granted by an admin.
     */
    public function hasPaidAccess(): bool
    {
        return $this->isPaying() || $this->isComped();
    }

    public function accessLabel(): string
    {
        return $this->isComped() && ! $this->isPaying() ? 'Complimentary' : $this->status->label();
    }
}
