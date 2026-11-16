<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Enums\PublishChannel;
use App\Enums\PublishTargetStatus;
use App\Enums\Role;
use App\Models\Post;
use App\Models\PublishTarget;
use App\Models\User;
use App\Services\Mailing\CampaignService;
use App\Services\Publishing\HeroImageRequirements;
use App\Services\Publishing\PublishTargetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishScheduledPostsCommand extends Command
{
    protected $signature = 'posts:publish-scheduled';

    protected $description = 'Publish due website posts and send due scheduled newsletter emails (Europe/London)';

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly HeroImageRequirements $heroRequirements,
        private readonly PublishTargetService $publishTargets,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $systemActor = User::query()->where('role', Role::Admin)->orderBy('id')->first();

        $publishedCount = $this->publishDueWebsitePosts($systemActor);
        $emailCount = $this->sendDueEmails($systemActor);

        $this->info("Published {$publishedCount} scheduled post(s).");
        $this->info("Sent {$emailCount} scheduled newsletter(s).");

        return self::SUCCESS;
    }

    private function publishDueWebsitePosts(?User $systemActor): int
    {
        $postIds = Post::query()
            ->where('status', PostStatus::Scheduled)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now('Europe/London'))
            ->pluck('id');

        $publishedCount = 0;
        $skippedCount = 0;

        foreach ($postIds as $postId) {
            $result = DB::transaction(function () use ($postId, $systemActor) {
                $post = Post::query()
                    ->whereKey($postId)
                    ->where('status', PostStatus::Scheduled)
                    ->whereNotNull('scheduled_for')
                    ->where('scheduled_for', '<=', now('Europe/London'))
                    ->lockForUpdate()
                    ->first();

                if (! $post) {
                    return null;
                }

                if ($this->heroRequirements->missingAlt($post)) {
                    return ['skipped' => true, 'title' => $post->title];
                }

                $actor = $systemActor ?? $post->author()->firstOrFail();
                $publishedAt = $post->scheduled_for;

                $post->update([
                    'status' => PostStatus::Published,
                    'published_at' => $publishedAt,
                    'scheduled_for' => null,
                ]);

                $this->publishTargets->ensureTargets($post);
                $this->publishTargets->markWebsiteSent($post, $publishedAt);

                // Email only if pending/now — independently scheduled email waits for its own due time.
                $email = $this->publishTargets->emailTarget($post);
                if ($email->status === PublishTargetStatus::Pending
                    || ($email->status === PublishTargetStatus::Scheduled && $email->isDue())) {
                    $this->campaigns->createFromPublishedPost($post->fresh(), $actor, force: true);
                }

                return ['skipped' => false, 'title' => $post->title];
            });

            if ($result === null) {
                continue;
            }

            if ($result['skipped']) {
                $skippedCount++;
                $this->warn("Skipped (hero image has no alt text): {$result['title']}");

                continue;
            }

            $publishedCount++;
            $this->line("Published: {$result['title']}");
        }

        if ($skippedCount > 0) {
            $this->warn("{$skippedCount} scheduled post(s) were left scheduled until alt text is added.");
        }

        return $publishedCount;
    }

    private function sendDueEmails(?User $systemActor): int
    {
        $targetIds = PublishTarget::query()
            ->where('channel', PublishChannel::Email)
            ->where('status', PublishTargetStatus::Scheduled)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now('Europe/London'))
            ->pluck('id');

        $sent = 0;

        foreach ($targetIds as $targetId) {
            $result = DB::transaction(function () use ($targetId, $systemActor) {
                $target = PublishTarget::query()
                    ->whereKey($targetId)
                    ->where('channel', PublishChannel::Email)
                    ->where('status', PublishTargetStatus::Scheduled)
                    ->whereNotNull('scheduled_for')
                    ->where('scheduled_for', '<=', now('Europe/London'))
                    ->lockForUpdate()
                    ->first();

                if (! $target) {
                    return null;
                }

                $post = Post::query()->lockForUpdate()->find($target->post_id);
                if (! $post || ! $post->status->isPubliclyVisible()) {
                    return null;
                }

                $actor = $systemActor ?? $post->author()->firstOrFail();
                $campaign = $this->campaigns->createFromPublishedPost($post, $actor, force: true);

                return $campaign !== null;
            });

            if ($result) {
                $sent++;
            }
        }

        return $sent;
    }
}
