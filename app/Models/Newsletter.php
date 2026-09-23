<?php

namespace App\Models;

use App\Enums\MailingList;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'legacy_list', 'archived_at'])]
class Newsletter extends Model
{
    protected function casts(): array
    {
        return [
            'legacy_list' => MailingList::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Newsletter>  $query
     * @return Builder<Newsletter>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @return HasMany<MailingListSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(MailingListSubscription::class);
    }
}
