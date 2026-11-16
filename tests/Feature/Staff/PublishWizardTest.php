<?php

namespace Tests\Feature\Staff;

use App\Enums\ContentVisibility;
use App\Enums\MailingList;
use App\Enums\MembershipStatus;
use App\Enums\PostStatus;
use App\Enums\PublishChannel;
use App\Enums\PublishTargetStatus;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Membership;
use App\Models\Post;
use App\Models\User;
use App\Services\Mailing\CampaignService;
use App\Services\Publishing\PublishTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

class PublishWizardTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesNewsletterSenders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifyNewsletterSenders();
        Mail::fake();
    }

    public function test_migration_backfill_preserves_email_on_publish_behaviour(): void
    {
        $withEmail = Post::factory()->published()->create([
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);
        $withoutEmail = Post::factory()->published()->create([
            'email_on_publish' => false,
        ]);

        $targets = app(PublishTargetService::class);
        $targets->ensureTargets($withEmail);
        $targets->ensureTargets($withoutEmail);

        $this->assertTrue($targets->wantsEmail($withEmail));
        $this->assertFalse($targets->wantsEmail($withoutEmail));
        $this->assertSame(
            PublishTargetStatus::Skipped,
            $targets->emailTarget($withoutEmail)->status,
        );
        $this->assertSame(
            PublishTargetStatus::Sent,
            $targets->websiteTarget($withEmail)->status,
        );
    }

    public function test_wizard_saves_audience_and_when_without_publishing(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::Draft,
        ]);

        $this->actingAs($admin)
            ->post(route('staff.posts.publish.audience', $post), [
                'visibility' => ContentVisibility::Members->value,
                'mailing_lists' => [MailingList::ApesCic->value],
                'email_mode' => 'now',
                'next' => 'when',
            ])
            ->assertRedirect(route('staff.posts.publish.show', ['post' => $post, 'step' => 'when']));

        $post->refresh();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame(ContentVisibility::Members, $post->visibility);
        $this->assertTrue($post->email_on_publish);

        $this->actingAs($admin)
            ->post(route('staff.posts.publish.when', $post), [
                'website_mode' => 'now',
                'email_mode' => 'schedule',
                'email_at' => now('Europe/London')->addHour()->format('Y-m-d\TH:i'),
                'next' => 'confirm',
            ])
            ->assertRedirect(route('staff.posts.publish.show', ['post' => $post, 'step' => 'confirm']));

        $post->refresh();
        $this->assertSame(PostStatus::Draft, $post->status);
        $email = app(PublishTargetService::class)->emailTarget($post);
        $this->assertSame(PublishTargetStatus::Scheduled, $email->status);
    }

    public function test_confirm_publishes_once_and_is_double_submit_safe(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedConfirmedContact('reader@example.com');
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::Draft,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
            'visibility' => ContentVisibility::Public,
        ]);

        $this->actingAs($admin)->post(route('staff.posts.publish.audience', $post), [
            'visibility' => ContentVisibility::Public->value,
            'mailing_lists' => [MailingList::ApesCic->value],
            'email_mode' => 'now',
        ]);
        $this->actingAs($admin)->post(route('staff.posts.publish.when', $post), [
            'website_mode' => 'now',
            'email_mode' => 'now',
        ]);

        $this->actingAs($admin)
            ->post(route('staff.posts.publish.confirm', $post))
            ->assertRedirect(route('staff.posts.publish.show', ['post' => $post, 'step' => 'result']));

        $this->actingAs($admin)
            ->post(route('staff.posts.publish.confirm', $post))
            ->assertRedirect(route('staff.posts.publish.show', ['post' => $post, 'step' => 'result']));

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $this->assertSame(1, Campaign::query()->where('post_id', $post->id)->where('is_test', false)->count());
        $this->assertSame(
            PublishTargetStatus::Sent,
            app(PublishTargetService::class)->websiteTarget($post)->status,
        );
    }

    public function test_email_cannot_be_scheduled_before_website(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create(['author_id' => $admin->id]);

        $this->actingAs($admin)
            ->from(route('staff.posts.publish.show', ['post' => $post, 'step' => 'when']))
            ->post(route('staff.posts.publish.when', $post), [
                'website_mode' => 'schedule',
                'website_at' => now('Europe/London')->addHours(3)->format('Y-m-d\TH:i'),
                'email_mode' => 'schedule',
                'email_at' => now('Europe/London')->addHour()->format('Y-m-d\TH:i'),
            ])
            ->assertSessionHasErrors('email_at');
    }

    public function test_cancelled_scheduled_email_does_not_send(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedConfirmedContact('reader@example.com');
        $post = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $targets = app(PublishTargetService::class);
        $targets->ensureTargets($post);
        $email = $targets->emailTarget($post);
        $email->update([
            'status' => PublishTargetStatus::Scheduled,
            'scheduled_for' => now('Europe/London')->subMinute(),
            'completed_at' => null,
            'meta' => ['email_mode' => 'schedule'],
        ]);

        $this->actingAs($admin)
            ->post(route('staff.posts.publish.cancel', ['post' => $post, 'channel' => 'email']))
            ->assertRedirect();

        $this->assertSame(PublishTargetStatus::Cancelled, $email->fresh()->status);
        $this->assertNotNull(AuditLog::query()->where('action', 'publish_target.cancelled')->first());

        $this->artisan('posts:publish-scheduled')->assertSuccessful();
        $this->assertSame(0, Campaign::query()->where('post_id', $post->id)->where('is_test', false)->count());
    }

    public function test_delayed_email_sends_when_due_and_is_idempotent(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedConfirmedContact('reader@example.com');
        $post = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $targets = app(PublishTargetService::class);
        $targets->ensureTargets($post);
        $targets->emailTarget($post)->update([
            'status' => PublishTargetStatus::Scheduled,
            'scheduled_for' => now('Europe/London')->subMinute(),
            'completed_at' => null,
        ]);

        $this->artisan('posts:publish-scheduled')->assertSuccessful();
        $this->artisan('posts:publish-scheduled')->assertSuccessful();

        $this->assertSame(1, Campaign::query()->where('post_id', $post->id)->where('is_test', false)->count());
    }

    public function test_free_members_post_emails_free_and_paid_members(): void
    {
        $admin = User::factory()->admin()->create();

        $freeUser = User::factory()->create(['email' => 'free@example.com']);
        Membership::query()->create(['user_id' => $freeUser->id, 'status' => MembershipStatus::Free]);
        $this->seedConfirmedContact('free@example.com', $freeUser);

        $paidUser = User::factory()->create(['email' => 'paid@example.com']);
        Membership::query()->create(['user_id' => $paidUser->id, 'status' => MembershipStatus::Active]);
        $this->seedConfirmedContact('paid@example.com', $paidUser);

        $this->seedConfirmedContact('outsider@example.com');

        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'visibility' => ContentVisibility::Members,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $campaign = app(CampaignService::class)->createFromPublishedPost(
            Post::factory()->published()->create([
                'author_id' => $admin->id,
                'visibility' => ContentVisibility::Members,
                'email_on_publish' => true,
                'mailing_lists' => [MailingList::ApesCic->value],
            ]),
            $admin,
            force: true,
        );

        $this->assertNotNull($campaign);
        $emails = $campaign->recipients()->pluck('email')->map(fn ($e) => strtolower($e))->all();
        $this->assertContains('free@example.com', $emails);
        $this->assertContains('paid@example.com', $emails);
        $this->assertNotContains('outsider@example.com', $emails);
        unset($post);
    }

    public function test_paid_only_post_emails_only_paid_members(): void
    {
        $admin = User::factory()->admin()->create();

        $freeUser = User::factory()->create(['email' => 'free2@example.com']);
        Membership::query()->create(['user_id' => $freeUser->id, 'status' => MembershipStatus::Free]);
        $this->seedConfirmedContact('free2@example.com', $freeUser);

        $paidUser = User::factory()->create(['email' => 'paid2@example.com']);
        Membership::query()->create(['user_id' => $paidUser->id, 'status' => MembershipStatus::Active]);
        $this->seedConfirmedContact('paid2@example.com', $paidUser);

        $campaign = app(CampaignService::class)->createFromPublishedPost(
            Post::factory()->published()->create([
                'author_id' => $admin->id,
                'visibility' => ContentVisibility::Paid,
                'email_on_publish' => true,
                'mailing_lists' => [MailingList::ApesCic->value],
            ]),
            $admin,
            force: true,
        );

        $emails = $campaign->recipients()->pluck('email')->map(fn ($e) => strtolower($e))->all();
        $this->assertSame(['paid2@example.com'], $emails);
    }

    public function test_result_page_exposes_canonical_share_url(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'slug' => 'share-me-please',
            'canonical_url' => 'https://www.apesnews.org.uk/articles/share-me-please',
        ]);

        app(PublishTargetService::class)->ensureTargets($post);
        app(PublishTargetService::class)->emailTarget($post)->update([
            'meta' => ['wizard_step' => 'result'],
        ]);

        $this->actingAs($admin)
            ->get(route('staff.posts.publish.show', ['post' => $post, 'step' => 'result']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Staff/Posts/Publish/Wizard')
                ->where('shareUrl', 'https://www.apesnews.org.uk/articles/share-me-please')
                ->where('step', 'result'));
    }

    public function test_legacy_publish_still_creates_publish_targets(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'email_on_publish' => false,
        ]);

        $this->actingAs($admin)->post(route('staff.posts.publish', $post))->assertRedirect();

        $this->assertDatabaseHas('publish_targets', [
            'post_id' => $post->id,
            'channel' => PublishChannel::Website->value,
            'status' => PublishTargetStatus::Sent->value,
        ]);
    }

    private function seedConfirmedContact(string $email, ?User $user = null): void
    {
        $contact = MailingContact::factory()->create([
            'email' => $email,
            'user_id' => $user?->id,
        ]);
        MailingListSubscription::create([
            'mailing_contact_id' => $contact->id,
            'list' => MailingList::ApesCic,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }
}
