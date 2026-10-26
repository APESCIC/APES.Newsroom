<?php

namespace App\Models;

use App\Enums\MailingList;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'description',
    'legacy_list',
    'ghost_slug',
    'sender_name',
    'sender_email',
    'reply_to',
    'subscribe_on_signup',
    'sender_verification',
    'archived_at',
])]
class Newsletter extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'legacy_list' => MailingList::class,
            'subscribe_on_signup' => 'boolean',
            'sender_verification' => 'array',
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

    /**
     * @param  Builder<Newsletter>  $query
     * @return Builder<Newsletter>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function status(): string
    {
        return $this->isArchived() ? self::STATUS_ARCHIVED : self::STATUS_ACTIVE;
    }

    /**
     * @return HasMany<NewsletterSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(NewsletterSegment::class);
    }

    /**
     * @return HasMany<MailingListSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(MailingListSubscription::class);
    }
}
