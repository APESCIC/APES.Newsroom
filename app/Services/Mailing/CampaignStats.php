<?php

namespace App\Services\Mailing;

use App\Enums\CampaignRecipientStatus;
use App\Models\Campaign;
use App\Models\MailEvent;
use App\Models\Suppression;
use Illuminate\Support\Facades\DB;

/**
 * Delivery and engagement counts for one campaign, from its recipients and
 * their stored mail events. Opens are indicative: Apple Mail Privacy
 * Protection opens messages on the reader's behalf, and readers who did not
 * allow open tracking are never counted.
 */
class CampaignStats
{
    public const TOP_LINKS = 5;

    /**
     * @return array{recipients: int, sent: int, failed: int, skipped: int, queued: int, opened: int, clicked: int, open_rate: float, click_rate: float, bounced: int, complained: int, top_links: list<array{url: string, clicks: int}>}
     */
    public function forCampaign(Campaign $campaign): array
    {
        $counts = $campaign->recipients()
            ->selectRaw('status, COUNT(*) as total, SUM(CASE WHEN opened_at IS NULL THEN 0 ELSE 1 END) as opened, SUM(CASE WHEN clicked_at IS NULL THEN 0 ELSE 1 END) as clicked')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status instanceof CampaignRecipientStatus ? $row->status->value : (string) $row->status);

        $count = fn (CampaignRecipientStatus $status) => (int) ($counts->get($status->value)->total ?? 0);
        $sent = $count(CampaignRecipientStatus::Accepted);
        $opened = (int) $counts->sum('opened');
        $clicked = (int) $counts->sum('clicked');

        $recipientIds = $campaign->recipients()->select('id');
        $failures = MailEvent::query()
            ->whereIn('campaign_recipient_id', $recipientIds)
            ->whereIn('type', [MailEvent::FAILED, MailEvent::COMPLAINED])
            ->select('type', 'severity', DB::raw('COUNT(DISTINCT recipient) as total'))
            ->groupBy('type', 'severity')
            ->get();

        $topLinks = MailEvent::query()
            ->whereIn('campaign_recipient_id', $campaign->recipients()->select('id'))
            ->where('type', MailEvent::CLICKED)
            ->whereNotNull('url')
            ->select('url', DB::raw('COUNT(DISTINCT recipient) as clicks'))
            ->groupBy('url')
            ->orderByDesc('clicks')
            ->limit(self::TOP_LINKS)
            ->get()
            ->map(fn (MailEvent $event) => ['url' => (string) $event->url, 'clicks' => (int) $event->getAttribute('clicks')])
            ->all();

        return [
            'recipients' => (int) $counts->sum('total'),
            'sent' => $sent,
            'failed' => $count(CampaignRecipientStatus::Failed),
            'skipped' => $count(CampaignRecipientStatus::Skipped),
            'queued' => $count(CampaignRecipientStatus::Queued),
            'opened' => $opened,
            'clicked' => $clicked,
            'open_rate' => $sent > 0 ? round($opened / $sent * 100, 1) : 0.0,
            'click_rate' => $sent > 0 ? round($clicked / $sent * 100, 1) : 0.0,
            'bounced' => (int) $failures->where('type', MailEvent::FAILED)->where('severity', 'permanent')->sum('total'),
            'complained' => (int) $failures->where('type', MailEvent::COMPLAINED)->sum('total'),
            'top_links' => $topLinks,
        ];
    }

    /**
     * Per-newsletter totals for live campaigns, newest activity first.
     *
     * @return list<array{newsletter: string, campaigns: int, sent: int, opened: int, clicked: int, open_rate: float, click_rate: float}>
     */
    public function byNewsletter(): array
    {
        return DB::table('campaign_recipients')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_recipients.campaign_id')
            ->join('newsletters', 'newsletters.id', '=', 'campaigns.newsletter_id')
            ->where('campaigns.is_test', false)
            ->where('campaign_recipients.status', CampaignRecipientStatus::Accepted->value)
            ->groupBy('newsletters.id', 'newsletters.name')
            ->orderBy('newsletters.name')
            ->selectRaw('newsletters.name as newsletter, COUNT(DISTINCT campaigns.id) as campaigns, COUNT(*) as sent, SUM(CASE WHEN campaign_recipients.opened_at IS NULL THEN 0 ELSE 1 END) as opened, SUM(CASE WHEN campaign_recipients.clicked_at IS NULL THEN 0 ELSE 1 END) as clicked')
            ->get()
            ->map(fn ($row) => [
                'newsletter' => (string) $row->newsletter,
                'campaigns' => (int) $row->campaigns,
                'sent' => (int) $row->sent,
                'opened' => (int) $row->opened,
                'clicked' => (int) $row->clicked,
                'open_rate' => (int) $row->sent > 0 ? round((int) $row->opened / (int) $row->sent * 100, 1) : 0.0,
                'click_rate' => (int) $row->sent > 0 ? round((int) $row->clicked / (int) $row->sent * 100, 1) : 0.0,
            ])
            ->all();
    }

    /**
     * Addresses suppressed by delivery events, by reason.
     *
     * @return array<string, int>
     */
    public function deliverySuppressions(): array
    {
        return Suppression::query()
            ->whereIn('reason', Suppression::DELIVERY_REASONS)
            ->selectRaw('reason, COUNT(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
