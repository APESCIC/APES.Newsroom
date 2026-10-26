<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'post_id', 'newsletter_id', 'created_by', 'idempotency_key', 'lists', 'snapshot', 'status',
    'failure_reason', 'is_test', 'test_recipient', 'queued_at', 'completed_at',
])]
class Campaign extends Model
{
    protected function casts(): array
    {
        return [
            'lists' => 'array',
            'snapshot' => 'array',
            'status' => CampaignStatus::class,
            'is_test' => 'boolean',
            'queued_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<Newsletter, $this>
     */
    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<CampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
