<?php

namespace App\Jobs;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\MailingList;
use App\Http\Controllers\Mailing\CampaignTrackingController;
use App\Mail\CampaignPostSummaryMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Mailing\ConsentService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

class SendCampaignRecipientJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $recipientId) {}

    public function uniqueId(): string
    {
        return 'campaign-recipient-'.$this->recipientId;
    }

    public function handle(ConsentService $consent): void
    {
        $recipient = CampaignRecipient::query()->with('campaign')->find($this->recipientId);

        if (! $recipient || ! $recipient->campaign) {
            return;
        }

        if ($recipient->status === CampaignRecipientStatus::Accepted) {
            return;
        }

        $campaign = $recipient->campaign;
        $recipient->increment('attempts');

        if (! $campaign->is_test) {
            if ($consent->isSuppressed($recipient->email)) {
                $recipient->update([
                    'status' => CampaignRecipientStatus::Skipped,
                    'last_error' => 'suppressed',
                ]);
                $this->maybeComplete($campaign->id);

                return;
            }

            $hasConsent = false;
            foreach ($campaign->lists as $listValue) {
                $list = MailingList::tryFrom($listValue);
                if ($list && $consent->hasConfirmedConsent($recipient->email, $list)) {
                    $hasConsent = true;
                    break;
                }
            }

            if (! $hasConsent) {
                $recipient->update([
                    'status' => CampaignRecipientStatus::Skipped,
                    'last_error' => 'no_confirmed_consent',
                ]);
                $this->maybeComplete($campaign->id);

                return;
            }
        }

        $oneClickUnsubscribeUrl = $this->signedOneClickUnsubscribeUrl($recipient->email);
        $unsubscribePageUrl = $this->signedUnsubscribePageUrl($recipient->email);
        $preferencesUrl = $this->signedPreferencesUrl($recipient->email);

        $readMore = (string) ($campaign->snapshot['read_more_url'] ?? url('/'));
        $openPixelUrl = $campaign->is_test ? null : CampaignTrackingController::openUrl($recipient);
        $trackedReadMoreUrl = $campaign->is_test
            ? $readMore
            : CampaignTrackingController::clickUrl($recipient, $readMore);

        Mail::to($recipient->email)->send(new CampaignPostSummaryMail(
            snapshot: $campaign->snapshot,
            unsubscribeUrl: $unsubscribePageUrl,
            preferencesUrl: $preferencesUrl,
            listUnsubscribeUrl: $oneClickUnsubscribeUrl,
            isTest: $campaign->is_test,
            openPixelUrl: $openPixelUrl,
            trackedReadMoreUrl: $trackedReadMoreUrl,
        ));

        $recipient->update([
            'status' => CampaignRecipientStatus::Accepted,
            'accepted_at' => now(),
            'last_error' => null,
        ]);

        $this->maybeComplete($campaign->id);
    }

    public function failed(?Throwable $exception): void
    {
        $recipient = CampaignRecipient::query()->find($this->recipientId);
        if (! $recipient) {
            return;
        }

        $recipient->update([
            'status' => CampaignRecipientStatus::Failed,
            'failed_at' => now(),
            'last_error' => $exception?->getMessage(),
        ]);

        $this->maybeComplete($recipient->campaign_id);
    }

    private function maybeComplete(int $campaignId): void
    {
        $pending = CampaignRecipient::query()
            ->where('campaign_id', $campaignId)
            ->where('status', CampaignRecipientStatus::Queued)
            ->exists();

        if (! $pending) {
            Campaign::query()->whereKey($campaignId)->update([
                'status' => CampaignStatus::Completed,
                'completed_at' => now(),
            ]);
        }
    }

    private function signedOneClickUnsubscribeUrl(string $email): string
    {
        return URL::temporarySignedRoute(
            'mailing.unsubscribe.one-click',
            now()->addDays(30),
            ['email' => $email],
        );
    }

    private function signedUnsubscribePageUrl(string $email): string
    {
        return URL::temporarySignedRoute(
            'mailing.unsubscribe',
            now()->addDays(30),
            ['email' => $email],
        );
    }

    private function signedPreferencesUrl(string $email): string
    {
        return URL::temporarySignedRoute(
            'mailing.preferences.signed',
            now()->addDays(30),
            ['email' => $email],
        );
    }
}
