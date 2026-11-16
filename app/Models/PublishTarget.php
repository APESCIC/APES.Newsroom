<?php

namespace App\Models;

use App\Enums\PublishChannel;
use App\Enums\PublishTargetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'post_id',
    'channel',
    'status',
    'scheduled_for',
    'started_at',
    'completed_at',
    'result_message',
    'campaign_id',
    'meta',
])]
class PublishTarget extends Model
{
    protected function casts(): array
    {
        return [
            'channel' => PublishChannel::class,
            'status' => PublishTargetStatus::class,
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta' => 'array',
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
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function isDue(?\DateTimeInterface $now = null): bool
    {
        if ($this->status !== PublishTargetStatus::Scheduled) {
            return false;
        }

        if ($this->scheduled_for === null) {
            return false;
        }

        $now ??= now('Europe/London');

        return $this->scheduled_for->lte($now);
    }
}
