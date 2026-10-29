<?php

namespace App\Jobs;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\MailingList;
use App\Http\Controllers\Mailing\CampaignTrackingController;
use App\Mail\CampaignPostSummaryMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Services\Mail\MailMessageRefused;
use App\Services\Mail\MailTransportUnavailable;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\ConsentService;
use App\Services\Mailing\SenderDomains;
use App\Services\Mailing\TrackingPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends one campaign email. A refusal of this one message fails only this
 * recipient; a transport that cannot send pauses the whole campaign (never
 * switching transport) so an admin can fix it and resume.
 */
class SendCampaignRecipientJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $recipientId) {}

    public function uniqueId(): string
    {
        return 'campaign-recipient-'.$this->recipientId;
    }

    public function handle(ConsentService $consent, CampaignSender $sender, ?TrackingPolicy $policy = null): void
    {
        $policy ??= app(TrackingPolicy::class);
        $recipient = CampaignRecipient::query()->with('campaign.newsletter')->find($this->recipientId);

        if (! $recipient || ! $recipient->campaign) {
            return;
        }

        if ($recipient->status === CampaignRecipientStatus::Accepted) {
            return;
        }

        $campaign = $recipient->campaign;
        if ($campaign->status === CampaignStatus::Paused) {
            return;
        }

        $recipient->increment('attempts');

        $senderProblems = $this->senderProblems($campaign, $sender);
        if ($senderProblems !== []) {
            Log::warning('Campaign send blocked: sender not verified or sending domain not approved.', [
                'campaign_id' => $campaign->id,
                'newsletter_id' => $campaign->newsletter_id,
                'reasons' => $senderProblems,
            ]);
            $recipient->update([
                'status' => CampaignRecipientStatus::Skipped,
                'last_error' => CampaignService::FAILURE_SENDER_UNVERIFIED,
            ]);
            $this->maybeComplete($campaign->id);

            return;
        }

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
        $tracking = $campaign->is_test
            ? ['opens' => false, 'clicks' => false, 'provider' => 'newsroom']
            : $policy->forRecipient($recipient->email, $campaign->snapshot['sender']['address'] ?? null);
        $ownTracking = $tracking['provider'] === 'newsroom';
        $openPixelUrl = $ownTracking && $tracking['opens'] ? CampaignTrackingController::openUrl($recipient) : null;
        $trackedReadMoreUrl = $ownTracking && $tracking['clicks'] ? CampaignTrackingController::clickUrl($recipient, $readMore) : $readMore;

        try {
            $sent = Mail::mailer(SenderDomains::newsletterMailer())->to($recipient->email)->send(new CampaignPostSummaryMail(
                snapshot: $campaign->snapshot,
                unsubscribeUrl: $unsubscribePageUrl,
                preferencesUrl: $preferencesUrl,
                listUnsubscribeUrl: $oneClickUnsubscribeUrl,
                isTest: $campaign->is_test,
                openPixelUrl: $openPixelUrl,
                trackedReadMoreUrl: $trackedReadMoreUrl,
                providerTrackOpens: ! $ownTracking && $tracking['opens'],
                providerTrackClicks: ! $ownTracking && $tracking['clicks'],
                campaignRecipientId: $recipient->id,
            ));
        } catch (MailMessageRefused $exception) {
            $this->markFailed($recipient, $exception);

            return;
        } catch (MailTransportUnavailable $exception) {
            if (! $exception->permanent) {
                throw $exception;
            }

            $this->pause($recipient, $exception);

            return;
        }

        $recipient->update([
            'status' => CampaignRecipientStatus::Accepted,
            'accepted_at' => now(),
            'last_error' => null,
            'provider_message_id' => $sent instanceof SentMessage ? trim((string) $sent->getMessageId(), '<> ') ?: null : null,
        ]);

        $this->maybeComplete($campaign->id);
    }

    /**
     * Retries are used up. A transport that kept failing pauses the campaign;
     * anything else fails this recipient.
     */
    public function failed(?Throwable $exception): void
    {
        $recipient = CampaignRecipient::query()->with('campaign')->find($this->recipientId);
        if (! $recipient) {
            return;
        }

        if ($exception instanceof TransportExceptionInterface && ! $exception instanceof MailMessageRefused && $recipient->campaign) {
            $this->pause($recipient, $exception);

            return;
        }

        $this->markFailed($recipient, $exception);
    }

    /**
     * Problems with the From and reply-to the email will actually use (the
     * campaign snapshot), as well as the newsletter's current verification.
     *
     * @return list<string>
     */
    private function senderProblems(Campaign $campaign, CampaignSender $sender): array
    {
        $problems = [];
        if ($campaign->newsletter_id !== null && ! $sender->isVerified($campaign->newsletter)) {
            $problems = $campaign->newsletter ? $sender->domainProblems($campaign->newsletter) : [];
            $problems = $problems === [] ? ['The newsletter sender is not verified.'] : $problems;
        }

        $snapshotSender = is_array($campaign->snapshot['sender'] ?? null) ? $campaign->snapshot['sender'] : [];

        return array_values(array_unique(array_filter([
            ...$problems,
            SenderDomains::senderProblem(isset($snapshotSender['address']) ? (string) $snapshotSender['address'] : null),
            SenderDomains::replyToProblem(isset($snapshotSender['reply_to']) ? (string) $snapshotSender['reply_to'] : null),
        ])));
    }

    private function pause(CampaignRecipient $recipient, Throwable $exception): void
    {
        $recipient->update(['last_error' => $exception->getMessage()]);
        app(CampaignService::class)->pause($recipient->campaign, $exception->getMessage());
    }

    private function markFailed(CampaignRecipient $recipient, ?Throwable $exception): void
    {
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
            Campaign::query()->whereKey($campaignId)->where('status', '!=', CampaignStatus::Paused->value)->update([
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
