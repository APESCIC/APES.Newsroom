<?php

namespace App\Services\Engagement;

use App\Enums\ModerationStatus;
use App\Models\Comment;
use App\Models\ModerationReport;
use App\Models\Profile;

class ModerationCounts
{
    /**
     * Unfiltered queue sizes for the moderation summary cards.
     *
     * @return array{profiles: int, comments: int, reports: int, suspended: int}
     */
    public function summary(): array
    {
        $profiles = Profile::query()
            ->toBase()
            ->selectRaw('sum(case when moderation_status = ? then 1 else 0 end) as pending', [ModerationStatus::Pending->value])
            ->selectRaw('sum(case when moderation_status = ? then 1 else 0 end) as suspended', [ModerationStatus::Suspended->value])
            ->first();

        return [
            'profiles' => (int) ($profiles->pending ?? 0),
            'comments' => Comment::query()->where('moderation_status', ModerationStatus::Pending)->count(),
            'reports' => ModerationReport::query()->where('status', 'open')->count(),
            'suspended' => (int) ($profiles->suspended ?? 0),
        ];
    }
}
