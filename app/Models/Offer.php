<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'code',
    'name',
    'discount_type',
    'discount_value',
    'starts_at',
    'ends_at',
    'max_redemptions',
    'redemption_count',
    'is_active',
    'stripe_coupon_id',
    'stripe_promotion_code_id',
])]
class Offer extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'discount_value' => 'integer',
            'max_redemptions' => 'integer',
            'redemption_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Offer $offer): void {
            $offer->code = Str::upper(trim($offer->code));
        });
    }

    /**
     * @return HasMany<OfferRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(OfferRedemption::class);
    }

    public function isCurrentlyValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        if ($this->max_redemptions !== null && $this->redemption_count >= $this->max_redemptions) {
            return false;
        }

        return true;
    }
}
