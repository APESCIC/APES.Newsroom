<?php

namespace App\Services\Analytics;

use App\Enums\CampaignRecipientStatus;
use App\Enums\MembershipStatus;
use App\Models\CampaignRecipient;
use App\Models\Membership;

class MetricsDashboard
{
    public function __construct(private readonly ContentViewRecorder $views) {}

    /**
     * @return array{
     *     web: array{views_7d: int, views_30d: int, signed_in_share_7d: float},
     *     newsletters: array{sent: int, opened: int, clicked: int, open_rate: float, click_rate: float},
     *     subscriptions: array{free: int, active: int, past_due: int, canceled: int, mrr_pence: int}
     * }
     */
    public function summary(): array
    {
        return [
            'web' => $this->web(),
            'newsletters' => $this->newsletters(),
            'subscriptions' => $this->subscriptions(),
        ];
    }

    /**
     * @return array{views_7d: int, views_30d: int, signed_in_share_7d: float}
     */
    public function web(): array
    {
        return $this->views->webSummary();
    }

    /**
     * @return array{sent: int, opened: int, clicked: int, open_rate: float, click_rate: float}
     */
    public function newsletters(): array
    {
        $sent = CampaignRecipient::query()
            ->where('status', CampaignRecipientStatus::Accepted)
            ->count();
        $opened = CampaignRecipient::query()->whereNotNull('opened_at')->count();
        $clicked = CampaignRecipient::query()->whereNotNull('clicked_at')->count();

        return [
            'sent' => $sent,
            'opened' => $opened,
            'clicked' => $clicked,
            'open_rate' => $sent > 0 ? (float) round(($opened / $sent) * 100, 1) : 0.0,
            'click_rate' => $sent > 0 ? (float) round(($clicked / $sent) * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array{free: int, active: int, past_due: int, canceled: int, mrr_pence: int}
     */
    public function subscriptions(): array
    {
        $free = Membership::query()->where('status', MembershipStatus::Free)->count();
        $active = Membership::query()->where('status', MembershipStatus::Active)->count();
        $pastDue = Membership::query()->where('status', MembershipStatus::PastDue)->count();
        $canceled = Membership::query()->where('status', MembershipStatus::Canceled)->count();

        $mrr = 0;
        $activeMemberships = Membership::query()
            ->where('status', MembershipStatus::Active)
            ->with('plan')
            ->get();

        foreach ($activeMemberships as $membership) {
            $plan = $membership->plan;
            if (! $plan) {
                continue;
            }
            $mrr += $plan->interval === 'year'
                ? (int) round($plan->amount_pence / 12)
                : $plan->amount_pence;
        }

        return [
            'free' => $free,
            'active' => $active,
            'past_due' => $pastDue,
            'canceled' => $canceled,
            'mrr_pence' => $mrr,
        ];
    }
}
