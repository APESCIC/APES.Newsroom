<?php

namespace App\Console\Commands;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Post;
use App\Models\User;
use App\Services\Mailing\CampaignService;
use App\Services\Publishing\HeroImageRequirements;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PublishScheduledPostsCommand extends Command
{
    protected $signature = 'posts:publish-scheduled';

    protected $description = 'Publish posts whose scheduled_for time has passed (Europe/London)';

    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly HeroImageRequirements $heroRequirements,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $postIds = Post::query()
            ->where('status', PostStatus::Scheduled)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now('Europe/London'))
            ->pluck('id');

        $systemActor = User::query()
            ->where('role', Role::SuperAdmin)
            ->orderBy('id')
            ->first()
            ?? User::query()->where('role', Role::Admin)->orderBy('id')->first();

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

                $this->campaigns->createFromPublishedPost($post->fresh(), $actor);

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

        $this->info("Published {$publishedCount} scheduled post(s).");
        if ($skippedCount > 0) {
            $this->warn("{$skippedCount} scheduled post(s) were left scheduled until alt text is added.");
        }

        return self::SUCCESS;
    }
}
