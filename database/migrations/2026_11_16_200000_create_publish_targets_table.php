<?php

use App\Enums\CampaignStatus;
use App\Enums\PostStatus;
use App\Enums\PublishChannel;
use App\Enums\PublishTargetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('status', 32);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('result_message')->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'channel']);
            $table->index(['channel', 'status', 'scheduled_for']);
        });

        $this->backfillExistingPosts();
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_targets');
    }

    private function backfillExistingPosts(): void
    {
        $now = now();

        DB::table('posts')->orderBy('id')->chunkById(100, function ($posts) use ($now): void {
            $rows = [];

            foreach ($posts as $post) {
                $rows[] = $this->websiteRow($post, $now);
                $rows[] = $this->emailRow($post, $now);
                $rows[] = [
                    'post_id' => $post->id,
                    'channel' => PublishChannel::Social->value,
                    'status' => PublishTargetStatus::Skipped->value,
                    'scheduled_for' => null,
                    'started_at' => null,
                    'completed_at' => $now,
                    'result_message' => 'Social cross-posting ships in v1.34.0.',
                    'campaign_id' => null,
                    'meta' => json_encode(['deferred' => true]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('publish_targets')->insert($rows);
            }
        });
    }

    /**
     * @param  object{id:int,status:string,published_at:?string,scheduled_for:?string}  $post
     * @return array<string, mixed>
     */
    private function websiteRow(object $post, mixed $now): array
    {
        $status = PublishTargetStatus::Pending->value;
        $scheduledFor = null;
        $startedAt = null;
        $completedAt = null;
        $message = null;

        if ($post->status === PostStatus::Published->value) {
            $status = PublishTargetStatus::Sent->value;
            $startedAt = $post->published_at;
            $completedAt = $post->published_at ?? $now;
        } elseif ($post->status === PostStatus::Scheduled->value) {
            $status = PublishTargetStatus::Scheduled->value;
            $scheduledFor = $post->scheduled_for;
        }

        return [
            'post_id' => $post->id,
            'channel' => PublishChannel::Website->value,
            'status' => $status,
            'scheduled_for' => $scheduledFor,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'result_message' => $message,
            'campaign_id' => null,
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  object{id:int,status:string,email_on_publish:int|bool,published_at:?string}  $post
     * @return array<string, mixed>
     */
    private function emailRow(object $post, mixed $now): array
    {
        $wantsEmail = (bool) $post->email_on_publish;

        if (! $wantsEmail) {
            return [
                'post_id' => $post->id,
                'channel' => PublishChannel::Email->value,
                'status' => PublishTargetStatus::Skipped->value,
                'scheduled_for' => null,
                'started_at' => null,
                'completed_at' => $now,
                'result_message' => null,
                'campaign_id' => null,
                'meta' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $campaign = DB::table('campaigns')
            ->where('post_id', $post->id)
            ->where('is_test', false)
            ->orderBy('id')
            ->first();

        $status = PublishTargetStatus::Pending->value;
        $startedAt = null;
        $completedAt = null;
        $message = null;
        $campaignId = $campaign?->id;

        if ($campaign) {
            $startedAt = $campaign->queued_at;
            $completedAt = $campaign->completed_at;
            $message = $campaign->failure_reason;

            $status = match ($campaign->status) {
                CampaignStatus::Completed->value => PublishTargetStatus::Sent->value,
                CampaignStatus::Failed->value, CampaignStatus::Cancelled->value => PublishTargetStatus::Failed->value,
                CampaignStatus::Queued->value, CampaignStatus::Sending->value, CampaignStatus::Paused->value => PublishTargetStatus::Sending->value,
                default => PublishTargetStatus::Sending->value,
            };
        } elseif ($post->status === PostStatus::Published->value) {
            // Published with the flag on but no campaign yet (e.g. no audience) — treat as skipped send attempt.
            $status = PublishTargetStatus::Skipped->value;
            $completedAt = $post->published_at ?? $now;
            $message = 'No email audience was selected when the post was published.';
        } elseif ($post->status === PostStatus::Scheduled->value) {
            $status = PublishTargetStatus::Pending->value;
        }

        return [
            'post_id' => $post->id,
            'channel' => PublishChannel::Email->value,
            'status' => $status,
            'scheduled_for' => null,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'result_message' => $message,
            'campaign_id' => $campaignId,
            'meta' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
