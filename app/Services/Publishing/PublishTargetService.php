<?php

namespace App\Services\Publishing;

use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Enums\PublishChannel;
use App\Enums\PublishTargetStatus;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\PublishTarget;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PublishTargetService
{
    /**
     * Ensure website, email and social targets exist for a post.
     * Existing posts keep behaviour via migration backfill; new posts get defaults here.
     *
     * @return Collection<string, PublishTarget>
     */
    public function ensureTargets(Post $post): Collection
    {
        return DB::transaction(function () use ($post) {
            $targets = PublishTarget::query()
                ->where('post_id', $post->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (PublishTarget $target) => $target->channel->value);

            foreach (PublishChannel::cases() as $channel) {
                if ($targets->has($channel->value)) {
                    continue;
                }

                $defaults = $this->defaultsForChannel($post, $channel);
                $created = PublishTarget::query()->create([
                    'post_id' => $post->id,
                    'channel' => $channel,
                    ...$defaults,
                ]);
                $targets->put($channel->value, $created);
            }

            return $targets;
        });
    }

    /**
     * @return Collection<string, PublishTarget>
     */
    public function targetsFor(Post $post): Collection
    {
        return $this->ensureTargets($post);
    }

    public function emailTarget(Post $post): PublishTarget
    {
        return $this->targetsFor($post)->get(PublishChannel::Email->value);
    }

    public function websiteTarget(Post $post): PublishTarget
    {
        return $this->targetsFor($post)->get(PublishChannel::Website->value);
    }

    /**
     * Whether email should be sent for this post (replaces sole reliance on email_on_publish).
     */
    public function wantsEmail(Post $post): bool
    {
        $email = PublishTarget::query()
            ->where('post_id', $post->id)
            ->where('channel', PublishChannel::Email)
            ->first();

        if ($email) {
            return $email->status->wantsSend();
        }

        return (bool) $post->email_on_publish;
    }

    /**
     * Keep the legacy email_on_publish column in sync with the email target.
     */
    public function syncEmailOnPublishFlag(Post $post): void
    {
        $wants = $this->wantsEmail($post);

        if ((bool) $post->email_on_publish !== $wants) {
            $post->forceFill(['email_on_publish' => $wants])->saveQuietly();
        }
    }

    public function markWebsiteSent(Post $post, ?\DateTimeInterface $at = null): PublishTarget
    {
        $at ??= now();
        $target = $this->websiteTarget($post);
        $target->update([
            'status' => PublishTargetStatus::Sent,
            'scheduled_for' => null,
            'started_at' => $target->started_at ?? $at,
            'completed_at' => $at,
            'result_message' => null,
        ]);

        return $target->fresh();
    }

    public function markWebsiteScheduled(Post $post, \DateTimeInterface $when): PublishTarget
    {
        $target = $this->websiteTarget($post);
        $target->update([
            'status' => PublishTargetStatus::Scheduled,
            'scheduled_for' => $when,
            'started_at' => null,
            'completed_at' => null,
            'result_message' => null,
        ]);

        return $target->fresh();
    }

    public function markEmailFromCampaign(Post $post, ?Campaign $campaign, ?string $fallbackMessage = null): PublishTarget
    {
        $target = $this->emailTarget($post);

        if (! $campaign) {
            $target->update([
                'status' => PublishTargetStatus::Skipped,
                'completed_at' => now(),
                'result_message' => $fallbackMessage ?? 'No email audience was selected.',
                'campaign_id' => null,
            ]);

            return $target->fresh();
        }

        $status = match ($campaign->status) {
            CampaignStatus::Completed => PublishTargetStatus::Sent,
            CampaignStatus::Failed, CampaignStatus::Cancelled => PublishTargetStatus::Failed,
            CampaignStatus::Queued, CampaignStatus::Sending, CampaignStatus::Paused => PublishTargetStatus::Sending,
            default => PublishTargetStatus::Sending,
        };

        $target->update([
            'status' => $status,
            'campaign_id' => $campaign->id,
            'started_at' => $campaign->queued_at ?? $target->started_at ?? now(),
            'completed_at' => $campaign->completed_at,
            'result_message' => $campaign->failure_reason ?? $fallbackMessage,
            'scheduled_for' => $status === PublishTargetStatus::Sending || $status === PublishTargetStatus::Sent
                ? null
                : $target->scheduled_for,
        ]);

        return $target->fresh();
    }

    public function cancelEmailSchedule(Post $post, ?string $message = null): PublishTarget
    {
        $target = $this->emailTarget($post);

        if ($target->status !== PublishTargetStatus::Scheduled) {
            return $target;
        }

        $target->update([
            'status' => PublishTargetStatus::Cancelled,
            'scheduled_for' => null,
            'completed_at' => now(),
            'result_message' => $message ?? 'Scheduled email cancelled.',
        ]);
        $this->syncEmailOnPublishFlag($post->fresh());

        return $target->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultsForChannel(Post $post, PublishChannel $channel): array
    {
        return match ($channel) {
            PublishChannel::Website => match ($post->status) {
                PostStatus::Published => [
                    'status' => PublishTargetStatus::Sent,
                    'scheduled_for' => null,
                    'started_at' => $post->published_at,
                    'completed_at' => $post->published_at ?? now(),
                    'result_message' => null,
                    'campaign_id' => null,
                    'meta' => null,
                ],
                PostStatus::Scheduled => [
                    'status' => PublishTargetStatus::Scheduled,
                    'scheduled_for' => $post->scheduled_for,
                    'started_at' => null,
                    'completed_at' => null,
                    'result_message' => null,
                    'campaign_id' => null,
                    'meta' => null,
                ],
                default => [
                    'status' => PublishTargetStatus::Pending,
                    'scheduled_for' => null,
                    'started_at' => null,
                    'completed_at' => null,
                    'result_message' => null,
                    'campaign_id' => null,
                    'meta' => null,
                ],
            },
            PublishChannel::Email => [
                'status' => $post->email_on_publish ? PublishTargetStatus::Pending : PublishTargetStatus::Skipped,
                'scheduled_for' => null,
                'started_at' => null,
                'completed_at' => $post->email_on_publish ? null : now(),
                'result_message' => null,
                'campaign_id' => null,
                'meta' => null,
            ],
            PublishChannel::Social => [
                'status' => PublishTargetStatus::Skipped,
                'scheduled_for' => null,
                'started_at' => null,
                'completed_at' => now(),
                'result_message' => 'Social cross-posting ships in v1.34.0.',
                'campaign_id' => null,
                'meta' => ['deferred' => true],
            ],
        };
    }
}
