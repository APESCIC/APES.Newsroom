<?php

namespace App\Services\Publishing;

use App\Enums\CampaignStatus;
use App\Enums\ContentVisibility;
use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Enums\PublishChannel;
use App\Enums\PublishTargetStatus;
use App\Models\Post;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\SlackEditorialNotifier;
use App\Services\Mailing\CampaignService;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishWizardService
{
    public const STEPS = ['audience', 'when', 'social', 'confirm', 'result'];

    public function __construct(
        private readonly PublishTargetService $targets,
        private readonly CampaignService $campaigns,
        private readonly HeroImageRequirements $heroRequirements,
        private readonly AuditLogger $audit,
        private readonly OutboundWebhookDispatcher $webhooks,
        private readonly SlackEditorialNotifier $slack,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function draftState(Post $post): array
    {
        $targets = $this->targets->targetsFor($post);
        $email = $targets->get(PublishChannel::Email->value);
        $website = $targets->get(PublishChannel::Website->value);
        $meta = is_array($email?->meta) ? $email->meta : [];

        return [
            'visibility' => $post->visibility?->value ?? ContentVisibility::Public->value,
            'mailing_lists' => $post->mailing_lists ?? [],
            'newsletter_segment_id' => $post->newsletter_segment_id,
            'newsletter_template' => $post->newsletter_template,
            'email_mode' => $meta['email_mode'] ?? $this->inferEmailMode($email),
            'website_mode' => $meta['website_mode'] ?? $this->inferWebsiteMode($website, $post),
            'website_at' => $website?->scheduled_for?->timezone('Europe/London')->format('Y-m-d\\TH:i')
                ?? ($post->scheduled_for?->timezone('Europe/London')->format('Y-m-d\\TH:i')),
            'email_at' => $email?->scheduled_for?->timezone('Europe/London')->format('Y-m-d\\TH:i'),
            'step' => $meta['wizard_step'] ?? 'audience',
        ];
    }

    /**
     * Persist Audience step choices without publishing.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveAudience(Post $post, array $input): Post
    {
        return DB::transaction(function () use ($post, $input) {
            $visibility = ContentVisibility::from($input['visibility']);
            $lists = $this->normalizeLists($input['mailing_lists'] ?? []);
            $segmentId = $input['newsletter_segment_id'] ?? null;
            $emailMode = $input['email_mode'] ?? 'now';

            $post->update([
                'visibility' => $visibility,
                'mailing_lists' => $lists,
                'newsletter_segment_id' => $segmentId,
                'newsletter_template' => $input['newsletter_template'] ?? $post->newsletter_template,
                'email_on_publish' => $emailMode !== 'dont',
            ]);

            $email = $this->targets->emailTarget($post);
            $status = $emailMode === 'dont' ? PublishTargetStatus::Skipped : PublishTargetStatus::Pending;
            $email->update([
                'status' => $status,
                'completed_at' => $status === PublishTargetStatus::Skipped ? now() : null,
                'result_message' => $status === PublishTargetStatus::Skipped ? 'Email will not be sent.' : null,
                'meta' => [
                    ...(is_array($email->meta) ? $email->meta : []),
                    'email_mode' => $emailMode,
                    'wizard_step' => 'audience',
                ],
            ]);

            $this->targets->syncEmailOnPublishFlag($post->fresh());

            return $post->fresh(['publishTargets']);
        });
    }

    /**
     * Persist When step choices without publishing.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveWhen(Post $post, array $input): Post
    {
        return DB::transaction(function () use ($post, $input) {
            $websiteMode = $input['website_mode'] ?? 'now';
            $emailMode = $input['email_mode'] ?? 'now';
            $websiteAt = $this->parseLondonTime($input['website_at'] ?? null);
            $emailAt = $this->parseLondonTime($input['email_at'] ?? null);

            if ($websiteMode === 'schedule' && $websiteAt === null) {
                throw ValidationException::withMessages([
                    'website_at' => 'Choose a website publish time in Europe/London.',
                ]);
            }

            if ($emailMode === 'schedule' && $emailAt === null) {
                throw ValidationException::withMessages([
                    'email_at' => 'Choose an email send time in Europe/London.',
                ]);
            }

            if ($emailMode === 'schedule' && $websiteMode === 'schedule' && $emailAt->lt($websiteAt)) {
                throw ValidationException::withMessages([
                    'email_at' => 'Email cannot be scheduled before the website post.',
                ]);
            }

            if ($emailMode === 'schedule' && $websiteMode === 'now' && $emailAt->lt(now('Europe/London'))) {
                throw ValidationException::withMessages([
                    'email_at' => 'Email cannot be scheduled in the past.',
                ]);
            }

            if ($emailMode === 'now' && $websiteMode === 'schedule') {
                throw ValidationException::withMessages([
                    'email_mode' => 'Email cannot go out before the website post is published. Schedule the email for the same time or later.',
                ]);
            }

            $website = $this->targets->websiteTarget($post);
            if ($websiteMode === 'schedule') {
                $website->update([
                    'status' => PublishTargetStatus::Scheduled,
                    'scheduled_for' => $websiteAt,
                    'meta' => [
                        ...(is_array($website->meta) ? $website->meta : []),
                        'website_mode' => 'schedule',
                        'wizard_step' => 'when',
                    ],
                ]);
            } else {
                $website->update([
                    'status' => PublishTargetStatus::Pending,
                    'scheduled_for' => null,
                    'meta' => [
                        ...(is_array($website->meta) ? $website->meta : []),
                        'website_mode' => 'now',
                        'wizard_step' => 'when',
                    ],
                ]);
            }

            $email = $this->targets->emailTarget($post);
            $emailMeta = [
                ...(is_array($email->meta) ? $email->meta : []),
                'email_mode' => $emailMode,
                'website_mode' => $websiteMode,
                'wizard_step' => 'when',
            ];

            if ($emailMode === 'dont') {
                $email->update([
                    'status' => PublishTargetStatus::Skipped,
                    'scheduled_for' => null,
                    'completed_at' => now(),
                    'result_message' => 'Email will not be sent.',
                    'meta' => $emailMeta,
                ]);
                $post->forceFill(['email_on_publish' => false])->saveQuietly();
            } elseif ($emailMode === 'schedule') {
                $email->update([
                    'status' => PublishTargetStatus::Scheduled,
                    'scheduled_for' => $emailAt,
                    'completed_at' => null,
                    'result_message' => null,
                    'meta' => $emailMeta,
                ]);
                $post->forceFill(['email_on_publish' => true])->saveQuietly();
            } else {
                $email->update([
                    'status' => PublishTargetStatus::Pending,
                    'scheduled_for' => null,
                    'completed_at' => null,
                    'result_message' => null,
                    'meta' => $emailMeta,
                ]);
                $post->forceFill(['email_on_publish' => true])->saveQuietly();
            }

            return $post->fresh(['publishTargets']);
        });
    }

    /**
     * Confirm and execute publish / schedule. Idempotent for already-published posts.
     *
     * @return array{post: Post, published: bool}
     */
    public function confirm(Post $post, User $actor, ?Request $request = null): array
    {
        $this->heroRequirements->assertPublishable($post);
        $this->targets->ensureTargets($post);

        $email = $this->targets->emailTarget($post);
        if ($email->status->wantsSend()) {
            $this->campaigns->assertSenderVerified($post->fresh());
        }

        $didPublish = false;

        $publishedPost = DB::transaction(function () use ($post, $actor, &$didPublish) {
            $locked = Post::query()->lockForUpdate()->findOrFail($post->id);
            $website = $this->targets->websiteTarget($locked);
            $email = $this->targets->emailTarget($locked);
            $websiteMeta = is_array($website->meta) ? $website->meta : [];
            $websiteMode = $websiteMeta['website_mode'] ?? ($website->status === PublishTargetStatus::Scheduled ? 'schedule' : 'now');

            if ($locked->status === PostStatus::Published) {
                // Already live: only kick email if pending/due.
                if ($email->status === PublishTargetStatus::Pending
                    || ($email->status === PublishTargetStatus::Scheduled && $email->isDue())) {
                    $this->campaigns->createFromPublishedPost($locked, $actor, force: $email->status === PublishTargetStatus::Pending);
                }

                return $locked;
            }

            if ($locked->status === PostStatus::Deleted) {
                throw ValidationException::withMessages([
                    'status' => 'Deleted posts cannot be published.',
                ]);
            }

            if ($websiteMode === 'schedule') {
                $when = $website->scheduled_for;
                if ($when === null) {
                    throw ValidationException::withMessages([
                        'website_at' => 'Choose a website publish time.',
                    ]);
                }

                $locked->update([
                    'status' => PostStatus::Scheduled,
                    'scheduled_for' => $when,
                ]);
                $this->targets->markWebsiteScheduled($locked, $when);

                return $locked->fresh();
            }

            $locked->update([
                'status' => PostStatus::Published,
                'published_at' => now(),
                'scheduled_for' => null,
                'review_notes' => null,
            ]);
            $this->targets->markWebsiteSent($locked);
            $didPublish = true;

            $fresh = $locked->fresh();
            if ($email->status === PublishTargetStatus::Pending) {
                $this->campaigns->createFromPublishedPost($fresh, $actor, force: true);
            } elseif ($email->status === PublishTargetStatus::Scheduled && $email->isDue()) {
                $this->campaigns->createFromPublishedPost($fresh, $actor, force: true);
            }

            return $fresh->fresh(['publishTargets']);
        });

        if ($didPublish) {
            $this->audit->record($actor, 'post.published', $publishedPost, [
                'via' => 'publish_wizard',
            ], $request);
            $this->webhooks->dispatch(OutboundWebhookDispatcher::EVENT_POST_PUBLISHED, [
                'post_id' => $publishedPost->id,
                'slug' => $publishedPost->slug,
                'title' => $publishedPost->title,
                'status' => $publishedPost->status->value,
                'published_at' => $publishedPost->published_at?->toIso8601String(),
            ]);
            $this->slack->notifyPostPublished($publishedPost);
        }

        $emailTarget = $this->targets->emailTarget($publishedPost);
        $emailTarget->update([
            'meta' => [
                ...(is_array($emailTarget->meta) ? $emailTarget->meta : []),
                'wizard_step' => 'result',
            ],
        ]);

        return ['post' => $publishedPost->fresh(['publishTargets']), 'published' => $didPublish];
    }

    public function retry(Post $post, PublishChannel $channel, User $actor, ?Request $request = null): PublishTarget
    {
        $target = $this->targets->targetsFor($post)->get($channel->value);

        if ($target->status !== PublishTargetStatus::Failed) {
            throw ValidationException::withMessages([
                'channel' => 'Only failed channels can be retried.',
            ]);
        }

        if ($channel === PublishChannel::Email) {
            $target->update([
                'status' => PublishTargetStatus::Pending,
                'result_message' => null,
                'completed_at' => null,
                'campaign_id' => null,
            ]);
            // Clear previous campaign idempotency by only retrying if no live campaign, or allow recreate via new key — existing service is idempotent on campaign. Soft-delete old? Keep idempotent: if campaign failed, resume/recreate path.
            if ($post->status === PostStatus::Published) {
                // Force a new attempt by clearing the failed campaign's lock path — CampaignService returns existing. Mark failed campaign cancelled first if present.
                if ($target->campaign_id) {
                    $campaign = $target->campaign;
                    if ($campaign) {
                        $campaign->update([
                            'status' => CampaignStatus::Cancelled,
                            'completed_at' => now(),
                            'idempotency_key' => ($campaign->idempotency_key ?? 'post:'.$post->id.':live').':failed-'.$campaign->id,
                        ]);
                    }
                }
                $target->update(['campaign_id' => null]);
                $this->campaigns->createFromPublishedPost($post->fresh(), $actor, force: true);
            }
        }

        $this->audit->record($actor, 'publish_target.retried', $post, [
            'channel' => $channel->value,
        ], $request);

        return $this->targets->targetsFor($post->fresh())->get($channel->value);
    }

    public function cancel(Post $post, PublishChannel $channel, User $actor, ?Request $request = null): PublishTarget
    {
        $target = $this->targets->targetsFor($post)->get($channel->value);

        if ($target->status !== PublishTargetStatus::Scheduled) {
            throw ValidationException::withMessages([
                'channel' => 'Only scheduled channels can be cancelled.',
            ]);
        }

        if ($channel === PublishChannel::Email) {
            $this->targets->cancelEmailSchedule($post, 'Scheduled email cancelled by staff.');
        } elseif ($channel === PublishChannel::Website) {
            $post->update([
                'status' => PostStatus::Draft,
                'scheduled_for' => null,
            ]);
            $target->update([
                'status' => PublishTargetStatus::Cancelled,
                'scheduled_for' => null,
                'completed_at' => now(),
                'result_message' => 'Scheduled website publish cancelled by staff.',
            ]);
        }

        $this->audit->record($actor, 'publish_target.cancelled', $post, [
            'channel' => $channel->value,
        ], $request);

        return $this->targets->targetsFor($post->fresh())->get($channel->value);
    }

    private function inferEmailMode(?PublishTarget $email): string
    {
        if (! $email) {
            return 'dont';
        }

        return match ($email->status) {
            PublishTargetStatus::Skipped, PublishTargetStatus::Cancelled => 'dont',
            PublishTargetStatus::Scheduled => 'schedule',
            default => 'now',
        };
    }

    private function inferWebsiteMode(?PublishTarget $website, Post $post): string
    {
        if ($website?->status === PublishTargetStatus::Scheduled || $post->status === PostStatus::Scheduled) {
            return 'schedule';
        }

        return 'now';
    }

    /**
     * @param  list<string>|mixed  $lists
     * @return list<string>
     */
    private function normalizeLists(mixed $lists): array
    {
        if (! is_array($lists)) {
            return [];
        }

        $out = [];
        foreach ($lists as $list) {
            $value = is_string($list) ? $list : (string) $list;
            if (MailingList::tryFrom($value)) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    private function parseLondonTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value, 'Europe/London');
    }
}
