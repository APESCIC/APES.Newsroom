<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

class PublishScheduledPostsCommandTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesNewsletterSenders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifyNewsletterSenders();
    }

    public function test_scheduled_posts_are_published_when_due(): void
    {
        $post = Post::factory()->create([
            'status' => PostStatus::Scheduled,
            'scheduled_for' => now('Europe/London')->subMinute(),
        ]);

        $this->artisan('posts:publish-scheduled')->assertSuccessful();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertNotNull($post->published_at);
    }

    public function test_scheduler_retries_reuse_one_campaign_and_fall_back_to_the_author(): void
    {
        $author = User::factory()->create();
        $post = Post::factory()->create([
            'author_id' => $author->id,
            'status' => PostStatus::Scheduled,
            'scheduled_for' => now('Europe/London')->subMinute(),
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $this->artisan('posts:publish-scheduled')->assertSuccessful();
        $this->artisan('posts:publish-scheduled')->assertSuccessful();

        $campaign = Campaign::query()->where('post_id', $post->id)->firstOrFail();
        $this->assertSame($author->id, $campaign->created_by);
        $this->assertSame('post:'.$post->id.':live', $campaign->idempotency_key);
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertSame(1, Campaign::query()->where('post_id', $post->id)->where('is_test', false)->count());
    }

    public function test_manual_publication_then_scheduler_does_not_duplicate_the_live_campaign(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::Scheduled,
            'scheduled_for' => now('Europe/London')->subMinute(),
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $this->actingAs($admin)->post(route('staff.posts.publish', $post))->assertRedirect();
        $this->artisan('posts:publish-scheduled')->assertSuccessful();

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $this->assertSame(1, Campaign::query()->where('post_id', $post->id)->where('is_test', false)->count());
    }
}
