<?php

namespace App\Services\Mailing;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\MailingList;
use App\Jobs\SendCampaignRecipientJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Newsletter;
use App\Models\NewsletterSegment;
use App\Models\Post;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\EditorJs\BlockRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CampaignService
{
    public const FAILURE_SENDER_UNVERIFIED = 'sender_unverified';

    public function __construct(
        private readonly ConsentService $consent,
        private readonly CampaignSender $sender,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Reject publishing with email when the campaign sender is not verified,
     * so staff see the problem before the post goes live.
     *
     * @throws ValidationException
     */
    public function assertSenderVerified(Post $post): void
    {
        if (! $post->email_on_publish) {
            return;
        }

        if ($this->normalizeListValues($post->mailing_lists ?? []) === [] && ! $post->newsletter_segment_id) {
            return;
        }

        $newsletter = $this->sender->newsletterForPost($post);
        if (! $this->sender->isVerified($newsletter)) {
            throw ValidationException::withMessages([
                'email_on_publish' => $this->sender->blockedMessage($newsletter),
            ]);
        }
    }

    /**
     * Create an immutable live campaign from a published post when email_on_publish is set.
     * Idempotent: returns existing live campaign if one already exists for the post.
     */
    public function createFromPublishedPost(Post $post, User $actor): ?Campaign
    {
        return DB::transaction(function () use ($post, $actor) {
            $lockedPost = Post::query()->lockForUpdate()->findOrFail($post->id);

            if (! $lockedPost->email_on_publish) {
                return null;
            }

            if (! $lockedPost->status->isPubliclyVisible()) {
                throw new \LogicException('A live campaign can only be created for a published post.');
            }

            $lists = $this->normalizeListValues($lockedPost->mailing_lists ?? []);
            $segment = $lockedPost->newsletter_segment_id
                ? NewsletterSegment::query()->with('newsletter', 'alsoNewsletter')->find($lockedPost->newsletter_segment_id)
                : null;

            if ($lists === [] && ! $segment) {
                return null;
            }

            $idempotencyKey = 'post:'.$lockedPost->id.':live';
            $legacyCampaign = Campaign::query()
                ->where('post_id', $lockedPost->id)
                ->where('is_test', false)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($legacyCampaign) {
                if ($legacyCampaign->idempotency_key === null) {
                    $legacyCampaign->update(['idempotency_key' => $idempotencyKey]);
                }

                return $legacyCampaign->load('recipients');
            }

            $senderNewsletter = $segment ? $segment->newsletter : $this->sender->newsletterForLists($lists);
            $senderVerified = $this->sender->isVerified($senderNewsletter);

            $campaign = Campaign::query()->firstOrCreate([
                'idempotency_key' => $idempotencyKey,
            ], [
                'post_id' => $lockedPost->id,
                'newsletter_id' => $senderNewsletter?->id,
                'created_by' => $actor->id,
                'lists' => $lists,
                'snapshot' => $this->buildSnapshot($lockedPost, $senderNewsletter),
                'status' => $senderVerified ? CampaignStatus::Queued : CampaignStatus::Failed,
                'failure_reason' => $senderVerified ? null : self::FAILURE_SENDER_UNVERIFIED,
                'is_test' => false,
                'queued_at' => now(),
                'completed_at' => $senderVerified ? null : now(),
            ]);

            if ($campaign->wasRecentlyCreated && ! $senderVerified) {
                $this->recordBlockedSend($senderNewsletter, $lockedPost, $actor, 'live');
            }

            if (! $campaign->wasRecentlyCreated || ! $senderVerified) {
                return $campaign->load('recipients');
            }

            $emails = $segment
                ? $this->consent->confirmedEmailsForSegment($segment)
                : $this->consent->confirmedEmailsForLists($lists);

            foreach ($emails as $email) {
                $this->createRecipient($campaign, $email);
            }

            $this->dispatchRecipients($campaign);

            return $campaign->fresh(['recipients']);
        });
    }

    public function createTestSend(Post $post, User $actor, string $recipientEmail): Campaign
    {
        $lists = $this->normalizeListValues($post->mailing_lists ?? []);
        if ($lists === []) {
            $lists = [MailingList::ApesCic->value];
        }

        $email = Str::lower(trim($recipientEmail));
        $senderNewsletter = $post->newsletter_segment_id
            ? $this->sender->newsletterForPost($post)
            : $this->sender->newsletterForLists($lists);

        if (! $this->sender->isVerified($senderNewsletter)) {
            $this->recordBlockedSend($senderNewsletter, $post, $actor, 'test');

            throw ValidationException::withMessages([
                'email' => $this->sender->blockedMessage($senderNewsletter),
            ]);
        }

        return DB::transaction(function () use ($post, $actor, $lists, $email, $senderNewsletter) {
            $campaign = Campaign::create([
                'post_id' => $post->id,
                'newsletter_id' => $senderNewsletter?->id,
                'created_by' => $actor->id,
                'lists' => $lists,
                'snapshot' => $this->buildSnapshot($post, $senderNewsletter),
                'status' => CampaignStatus::Queued,
                'is_test' => true,
                'test_recipient' => $email,
                'queued_at' => now(),
            ]);

            $recipient = $this->createRecipient($campaign, $email);
            SendCampaignRecipientJob::dispatch($recipient->id)->onQueue('mail-test');

            return $campaign->fresh(['recipients']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(Post $post, ?Newsletter $senderNewsletter = null): array
    {
        $post->loadMissing('author');

        $content = is_array($post->content) ? $post->content : [];
        $visibility = $post->visibility?->value ?? 'public';
        $html = $visibility === 'public'
            ? app(BlockRenderer::class)->toHtml($content)
            : null;

        $excerpt = $post->excerpt;
        if ($visibility !== 'public') {
            $cta = $visibility === 'paid'
                ? 'This article is for paid members. Subscribe in your account to read the full post.'
                : 'This article is for members. Create a free account to read the full post.';
            $excerpt = trim((string) $excerpt);
            $excerpt = $excerpt !== '' ? $excerpt."\n\n".$cta : $cta;
        }

        return [
            'title' => $post->title,
            'excerpt' => $excerpt,
            'html' => $html,
            'visibility' => $visibility,
            'hero_image' => $post->hero_image,
            'hero_image_alt' => $post->hero_image_alt,
            'author' => $post->author->name,
            'channel' => $post->channel->value,
            'channel_label' => $post->channel->label(),
            'published_at' => $post->published_at?->toIso8601String(),
            'read_more_url' => url('/articles/'.$post->slug),
            'slug' => $post->slug,
            'sender' => $this->sender->identity($senderNewsletter),
        ];
    }

    public function previewPayload(Post $post): array
    {
        $senderNewsletter = $this->sender->newsletterForPost($post)
            ?? $this->sender->newsletterForLists([MailingList::ApesCic->value]);

        return [
            ...$this->buildSnapshot($post, $senderNewsletter),
            'sender_verified' => $this->sender->isVerified($senderNewsletter),
        ];
    }

    private function recordBlockedSend(?Newsletter $newsletter, Post $post, User $actor, string $kind): void
    {
        $this->audit->record($actor, 'newsletter.send_blocked', $newsletter, [
            'kind' => $kind,
            'post_id' => $post->id,
            'newsletter' => $newsletter?->slug,
            'sender_email' => $newsletter?->sender_email,
            'reasons' => $newsletter ? $this->sender->domainProblems($newsletter) : [],
            'message' => $this->sender->blockedMessage($newsletter),
        ]);
    }

    private function createRecipient(Campaign $campaign, string $email): CampaignRecipient
    {
        return CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'email' => $email,
            'status' => CampaignRecipientStatus::Queued,
            'idempotency_key' => hash('sha256', $campaign->id.'|'.$email),
            'attempts' => 0,
        ]);
    }

    private function dispatchRecipients(Campaign $campaign): void
    {
        $recipients = $campaign->recipients()
            ->where('status', CampaignRecipientStatus::Queued)
            ->orderBy('id')
            ->get();

        if ($recipients->isEmpty()) {
            $campaign->update([
                'status' => CampaignStatus::Completed,
                'completed_at' => now(),
            ]);

            return;
        }

        $delaySeconds = 0;
        $perMinute = (int) config('mailing.throttle_per_minute', 60);
        $interval = $perMinute > 0 ? (int) ceil(60 / $perMinute) : 1;

        $campaign->update(['status' => CampaignStatus::Sending]);

        DB::afterCommit(function () use ($recipients, &$delaySeconds, $interval) {
            $recipients->each(function (CampaignRecipient $recipient) use (&$delaySeconds, $interval) {
                SendCampaignRecipientJob::dispatch($recipient->id)
                    ->delay(now()->addSeconds($delaySeconds));
                $delaySeconds += $interval;
            });
        });
    }

    /**
     * @param  list<string>  $lists
     * @return list<string>
     */
    private function normalizeListValues(array $lists): array
    {
        $out = [];
        foreach ($lists as $list) {
            $enum = MailingList::tryFrom((string) $list);
            if ($enum) {
                $out[$enum->value] = $enum->value;
            }
        }

        return array_values($out);
    }
}
