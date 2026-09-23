<?php

namespace Tests\Feature;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendCampaignRecipientJob;
use App\Mail\CampaignPostSummaryMail;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Post;
use App\Models\Suppression;
use App\Models\User;
use App\Notifications\ConfirmMailingListNotification;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\ConsentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MailingConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_requires_at_least_one_list_and_none_preselected(): void
    {
        $this->get('/mailing/signup')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Mailing/Signup')
            ->has('lists', 3)
        );

        $this->post('/mailing/signup', [
            'email' => 'reader@example.com',
            'lists' => [],
        ])->assertSessionHasErrors('lists');
    }

    public function test_signup_sends_double_opt_in_and_confirm_activates_list(): void
    {
        Notification::fake();

        $this->post('/mailing/signup', [
            'email' => 'reader@example.com',
            'lists' => [MailingList::ApesCic->value],
        ])->assertRedirect();

        $contact = MailingContact::query()->where('email', 'reader@example.com')->firstOrFail();
        $subscription = $contact->subscriptions()->firstOrFail();

        $this->assertSame(SubscriptionStatus::Pending, $subscription->status);

        Notification::assertSentTo($contact, ConfirmMailingListNotification::class);

        $this->get('/mailing/confirm/'.$subscription->confirm_token)->assertOk();

        $this->assertSame(SubscriptionStatus::Confirmed, $subscription->fresh()->status);
        $this->assertDatabaseHas('consent_events', [
            'email' => 'reader@example.com',
            'action' => 'confirmed',
            'list' => MailingList::ApesCic->value,
        ]);
    }

    public function test_unsubscribe_takes_effect_before_send_without_auth(): void
    {
        Mail::fake();

        $contact = MailingContact::factory()->create(['email' => 'reader@example.com']);
        MailingListSubscription::create([
            'mailing_contact_id' => $contact->id,
            'list' => MailingList::ApesCic,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $url = URL::temporarySignedRoute(
            'mailing.unsubscribe.one-click',
            now()->addDay(),
            ['email' => 'reader@example.com'],
        );

        $this->post($url)->assertNoContent();

        $this->assertSame(
            SubscriptionStatus::Unsubscribed,
            $contact->subscriptions()->first()->fresh()->status,
        );

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
            'status' => PostStatus::Draft,
        ]);

        $this->actingAs($admin)->post("/staff/posts/{$post->id}/publish")->assertRedirect();

        $campaign = Campaign::query()->where('post_id', $post->id)->where('is_test', false)->first();
        $this->assertNotNull($campaign);
        $this->assertSame(0, $campaign->recipients()->count());
    }

    public function test_suppressed_contacts_never_receive_campaign_mail(): void
    {
        Mail::fake();

        Suppression::create(['email' => 'blocked@example.com', 'reason' => 'objection']);

        $contact = MailingContact::factory()->create(['email' => 'blocked@example.com']);
        MailingListSubscription::create([
            'mailing_contact_id' => $contact->id,
            'list' => MailingList::ApesCic,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $emails = app(ConsentService::class)->confirmedEmailsForLists([MailingList::ApesCic]);
        $this->assertNotContains('blocked@example.com', $emails);
    }

    public function test_multi_list_recipients_are_de_duplicated(): void
    {
        $contact = MailingContact::factory()->create(['email' => 'multi@example.com']);

        foreach ([MailingList::ApesCic, MailingList::ApesShelterRescue] as $list) {
            MailingListSubscription::create([
                'mailing_contact_id' => $contact->id,
                'list' => $list,
                'status' => SubscriptionStatus::Confirmed,
                'confirmed_at' => now(),
            ]);
        }

        $emails = app(ConsentService::class)->confirmedEmailsForLists([
            MailingList::ApesCic,
            MailingList::ApesShelterRescue,
        ]);

        $this->assertSame(['multi@example.com'], $emails);
    }

    public function test_publish_creates_immutable_campaign_snapshot_once(): void
    {
        Mail::fake();

        $contact = MailingContact::factory()->create(['email' => 'reader@example.com']);
        MailingListSubscription::create([
            'mailing_contact_id' => $contact->id,
            'list' => MailingList::ApesCic,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'title' => 'Original Title',
            'excerpt' => 'Original excerpt',
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $this->actingAs($admin)->post("/staff/posts/{$post->id}/publish")->assertRedirect();
        $this->actingAs($admin)->post("/staff/posts/{$post->id}/publish")->assertRedirect();

        $this->assertSame(1, Campaign::query()->where('post_id', $post->id)->where('is_test', false)->count());

        $campaign = Campaign::query()->where('post_id', $post->id)->first();
        $this->assertSame('Original Title', $campaign->snapshot['title']);

        $post->update(['title' => 'Changed After Publish']);
        $this->assertSame('Original Title', $campaign->fresh()->snapshot['title']);
        $this->assertStringContainsString('<p>', (string) $campaign->snapshot['html']);
    }

    public function test_live_snapshot_html_is_immutable_and_rendered_in_mail(): void
    {
        Notification::fake();
        Mail::fake();

        $admin = User::factory()->admin()->create();
        app(ConsentService::class)->signup('reader@example.com', [MailingList::ApesCic->value], 'test');
        $subscription = MailingListSubscription::query()->first();
        $subscription->update([
            'status' => SubscriptionStatus::Confirmed,
            'confirm_token' => null,
            'confirmed_at' => now(),
        ]);

        $post = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'FullPostBodyPhrase']],
                ],
                'version' => '2.29.0',
            ],
        ]);

        $campaign = app(CampaignService::class)->createFromPublishedPost($post, $admin);
        $this->assertStringContainsString('FullPostBodyPhrase', (string) $campaign->snapshot['html']);

        $post->update([
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Edited after send']],
                ],
                'version' => '2.29.0',
            ],
        ]);

        $again = app(CampaignService::class)->createFromPublishedPost($post->fresh(), $admin);
        $this->assertTrue($campaign->is($again));
        $this->assertStringContainsString('FullPostBodyPhrase', (string) $again->snapshot['html']);
        $this->assertStringNotContainsString('Edited after send', (string) $again->snapshot['html']);

        $recipient = $campaign->recipients()->first();
        (new SendCampaignRecipientJob($recipient->id))->handle(app(ConsentService::class));

        Mail::assertSent(CampaignPostSummaryMail::class, function (CampaignPostSummaryMail $mail) {
            return str_contains($mail->render(), 'FullPostBodyPhrase');
        });
    }

    public function test_live_campaign_uses_a_stable_database_idempotency_key(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $service = app(CampaignService::class);
        $first = $service->createFromPublishedPost($post, $admin);
        $second = $service->createFromPublishedPost($post->fresh(), $admin);

        $this->assertNotNull($first);
        $this->assertTrue($first->is($second));
        $this->assertSame('post:'.$post->id.':live', $first->idempotency_key);
        $this->assertSame(1, Campaign::query()->where('post_id', $post->id)->where('is_test', false)->count());
    }

    public function test_database_rejects_duplicate_live_campaign_idempotency_keys(): void
    {
        $admin = User::factory()->admin()->create();
        $posts = Post::factory()->count(2)->published()->create(['author_id' => $admin->id]);
        $duplicateKey = 'post:shared:live';

        Campaign::query()->create([
            'post_id' => $posts[0]->id,
            'created_by' => $admin->id,
            'idempotency_key' => $duplicateKey,
            'lists' => [MailingList::ApesCic->value],
            'snapshot' => ['title' => 'First campaign'],
            'status' => CampaignStatus::Draft,
            'is_test' => false,
        ]);

        try {
            Campaign::query()->create([
                'post_id' => $posts[1]->id,
                'created_by' => $admin->id,
                'idempotency_key' => $duplicateKey,
                'lists' => [MailingList::ApesCic->value],
                'snapshot' => ['title' => 'Duplicate campaign'],
                'status' => CampaignStatus::Draft,
                'is_test' => false,
            ]);
            $this->fail('The database accepted a duplicate live campaign idempotency key.');
        } catch (QueryException) {
            $this->assertSame(1, Campaign::query()->where('idempotency_key', $duplicateKey)->count());
        }
    }

    public function test_legacy_live_campaign_is_reused_and_backfilled_with_the_stable_key(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);
        $legacy = Campaign::create([
            'post_id' => $post->id,
            'created_by' => $admin->id,
            'idempotency_key' => null,
            'lists' => [MailingList::ApesCic->value],
            'snapshot' => ['title' => $post->title],
            'status' => CampaignStatus::Completed,
            'is_test' => false,
            'queued_at' => now(),
            'completed_at' => now(),
        ]);

        $campaign = app(CampaignService::class)->createFromPublishedPost($post, $admin);

        $this->assertTrue($legacy->is($campaign));
        $this->assertSame('post:'.$post->id.':live', $campaign->idempotency_key);
        $this->assertSame(1, Campaign::query()->where('post_id', $post->id)->where('is_test', false)->count());
    }

    public function test_zero_recipient_live_campaign_completes_immediately(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $campaign = app(CampaignService::class)->createFromPublishedPost($post, $admin);

        $this->assertNotNull($campaign);
        $this->assertSame(CampaignStatus::Completed, $campaign->status);
        $this->assertNotNull($campaign->completed_at);
        $this->assertSame(0, $campaign->recipients()->count());
    }

    public function test_test_sends_remain_independent_of_live_idempotency(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);
        $service = app(CampaignService::class);

        $first = $service->createTestSend($post, $admin, 'tester@example.com');
        $second = $service->createTestSend($post, $admin, 'tester@example.com');

        $this->assertFalse($first->is($second));
        $this->assertNull($first->idempotency_key);
        $this->assertNull($second->idempotency_key);
        $this->assertSame(2, Campaign::query()->where('post_id', $post->id)->where('is_test', true)->count());
    }

    public function test_test_send_cannot_become_live_campaign(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $this->actingAs($admin)->post("/staff/posts/{$post->id}/campaign/test-send", [
            'email' => 'tester@example.com',
        ])->assertRedirect();

        $test = Campaign::query()->where('is_test', true)->firstOrFail();
        $this->assertTrue($test->is_test);
        $this->assertSame('tester@example.com', $test->test_recipient);
        $this->assertSame(0, Campaign::query()->where('is_test', false)->count());

        Mail::assertSent(CampaignPostSummaryMail::class, function (CampaignPostSummaryMail $mail) {
            return $mail->isTest === true
                && $mail->hasTo('tester@example.com');
        });
    }

    public function test_campaign_mail_includes_list_unsubscribe_headers(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'title' => 'Header Check',
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $campaign = Campaign::create([
            'post_id' => $post->id,
            'created_by' => $admin->id,
            'lists' => [MailingList::ApesCic->value],
            'snapshot' => app(CampaignService::class)->buildSnapshot($post),
            'status' => CampaignStatus::Sending,
            'is_test' => true,
            'test_recipient' => 'headers@example.com',
            'queued_at' => now(),
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'email' => 'headers@example.com',
            'status' => CampaignRecipientStatus::Queued,
            'idempotency_key' => hash('sha256', $campaign->id.'|headers@example.com'),
        ]);

        (new SendCampaignRecipientJob($recipient->id))->handle(app(ConsentService::class));

        Mail::assertSent(CampaignPostSummaryMail::class, function (CampaignPostSummaryMail $mail) {
            $headers = $mail->headers();

            return str_contains($headers->text['List-Unsubscribe'] ?? '', 'http')
                && ($headers->text['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click';
        });
    }

    public function test_recipient_job_is_idempotent_after_acceptance(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create(['author_id' => $admin->id]);

        $campaign = Campaign::create([
            'post_id' => $post->id,
            'created_by' => $admin->id,
            'lists' => [MailingList::ApesCic->value],
            'snapshot' => ['title' => 'T', 'excerpt' => null, 'author' => 'A', 'channel' => 'apes_cic', 'channel_label' => 'APES CIC', 'read_more_url' => 'http://localhost/articles/x', 'slug' => 'x'],
            'status' => CampaignStatus::Sending,
            'is_test' => true,
            'test_recipient' => 'once@example.com',
            'queued_at' => now(),
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'email' => 'once@example.com',
            'status' => CampaignRecipientStatus::Queued,
            'idempotency_key' => 'unique-key-1',
        ]);

        $job = new SendCampaignRecipientJob($recipient->id);
        $job->handle(app(ConsentService::class));
        $job->handle(app(ConsentService::class));

        Mail::assertSent(CampaignPostSummaryMail::class, 1);
        $this->assertSame(CampaignRecipientStatus::Accepted, $recipient->fresh()->status);
    }

    public function test_staff_cannot_test_send_campaign(): void
    {
        $staff = User::factory()->staff()->create();
        $post = Post::factory()->create(['author_id' => $staff->id]);

        $this->actingAs($staff)->post("/staff/posts/{$post->id}/campaign/test-send", [
            'email' => 'x@example.com',
        ])->assertForbidden();
    }
}
