<?php

namespace App\Models;

use App\Enums\CampaignRecipientStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'email', 'status', 'attempts', 'idempotency_key',
    'last_error', 'accepted_at', 'opened_at', 'clicked_at', 'failed_at',
])]
class CampaignRecipient extends Model
{
    protected function casts(): array
    {
        return [
            'status' => CampaignRecipientStatus::class,
            'accepted_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
