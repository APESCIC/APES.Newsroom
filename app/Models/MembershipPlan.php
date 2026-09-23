<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'interval',
    'amount_pence',
    'currency',
    'stripe_price_id',
    'is_active',
])]
class MembershipPlan extends Model
{
    protected function casts(): array
    {
        return [
            'amount_pence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
