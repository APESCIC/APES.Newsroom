<?php

namespace App\Models;

use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mailing_contact_id', 'newsletter_id', 'list', 'status', 'confirm_token',
    'confirmed_at', 'unsubscribed_at',
])]
class MailingListSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'list' => MailingList::class,
            'status' => SubscriptionStatus::class,
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MailingContact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(MailingContact::class, 'mailing_contact_id');
    }

    /**
     * @return BelongsTo<Newsletter, $this>
     */
    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === SubscriptionStatus::Confirmed;
    }
}
