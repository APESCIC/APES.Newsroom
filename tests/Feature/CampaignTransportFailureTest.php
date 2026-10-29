<?php

namespace Tests\Feature;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Enums\SendingDomainStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendCampaignRecipientJob;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\EmailSendingDomain;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CampaignPausedNotification;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

class CampaignTransportFailureTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesNewsletterSenders;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.mailgun.secret' => 'test-key', 'services.mailgun.domain' => null]);
        $this->verifyNewsletterSenders();
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * A live campaign to the given readers, with its jobs not yet run.
     *
     * @param  list<string>  $emails
     */
    private function campaign(array $emails = ['reader@example.com']): Campaign
    {
        $newsletterId = Newsletter::query()->where('legacy_list', MailingList::ApesCic->value)->value('id');
        foreach ($emails as $email) {
            $contact = MailingContact::query()->create(['email' => $email]);
            MailingListSubscription::query()->create([
                'mailing_contact_id' => $contact->id,
                'list' => MailingList::ApesCic,
                'newsletter_id' => $newsletterId,
                'status' => SubscriptionStatus::Confirmed,
                'confirmed_at' => now(),
            ]);
        }

        $post = Post::factory()->create([
            'author_id' => $this->admin->id,
            'status' => PostStatus::Published,
            'published_at' => now(),
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        Queue::fake();
        $campaign = app(CampaignService::class)->createFromPublishedPost($post, $this->admin);

        return $campaign->fresh();
    }

    private function sendTo(int $recipientId): void
    {
        (new SendCampaignRecipientJob($recipientId))->handle(app(ConsentService::class), app(CampaignSender::class));
    }

    public function test_a_refused_message_fails_only_that_recipient(): void
    {
        Http::fake(['api.eu.mailgun.net/v3/*' => Http::response(['message' => "'to' parameter is not a valid address."], 400)]);
        $campaign = $this->campaign(['bad@example.com', 'good@example.com']);
        [$bad, $good] = $campaign->recipients()->orderBy('email')->get()->all();

        $this->sendTo($bad->id);

        $this->assertSame(CampaignRecipientStatus::Failed, $bad->fresh()->status);
        $this->assertStringContainsString('HTTP 400', $bad->fresh()->last_error);
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);
        $this->assertSame(CampaignRecipientStatus::Queued, $good->fresh()->status);
    }

    public function test_a_transport_that_cannot_send_pauses_the_campaign_and_alerts_admins_once(): void
    {
        Notification::fake();
        Http::fake(['api.eu.mailgun.net/v3/*' => Http::response(['message' => 'Forbidden'], 401)]);
        $campaign = $this->campaign(['one@example.com', 'two@example.com']);
        [$one, $two] = $campaign->recipients()->orderBy('email')->get()->all();

        $this->sendTo($one->id);
        $this->sendTo($two->id);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Paused, $campaign->status);
        $this->assertStringContainsString('HTTP 401', $campaign->pause_reason);
        $this->assertSame(CampaignRecipientStatus::Queued, $one->fresh()->status);
        $this->assertSame(CampaignRecipientStatus::Queued, $two->fresh()->status);
        $this->assertSame(1, $one->fresh()->attempts);
        $this->assertSame(0, $two->fresh()->attempts);
        Notification::assertSentTo($this->admin, CampaignPausedNotification::class, fn (CampaignPausedNotification $notification) => $notification->remaining === 2);
        Notification::assertSentTimes(CampaignPausedNotification::class, 1);
        $this->assertTrue(AuditLog::query()->where('action', 'campaign.paused')->exists());
    }

    public function test_an_outage_is_retried_and_pauses_once_retries_are_used_up(): void
    {
        Notification::fake();
        Http::fake(['api.eu.mailgun.net/v3/*' => Http::response(['message' => 'Service unavailable'], 503)]);
        $campaign = $this->campaign();
        $recipient = $campaign->recipients()->firstOrFail();

        try {
            $this->sendTo($recipient->id);
            $this->fail('A temporary outage must be retried.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }
        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);

        (new SendCampaignRecipientJob($recipient->id))->failed($exception);

        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);
        $this->assertSame(CampaignRecipientStatus::Queued, $recipient->fresh()->status);
        Notification::assertSentTimes(CampaignPausedNotification::class, 1);
        $this->assertSame([30, 120], (new SendCampaignRecipientJob($recipient->id))->backoff);
    }

    public function test_an_admin_resumes_a_paused_campaign(): void
    {
        Notification::fake();
        Http::fake(['api.eu.mailgun.net/v3/*' => Http::sequence()
            ->push(['message' => 'Forbidden'], 401)
            ->push(['id' => '<resumed@mailgun.test>'])]);
        $campaign = $this->campaign();
        $recipient = $campaign->recipients()->firstOrFail();
        $this->sendTo($recipient->id);
        $this->assertSame(CampaignStatus::Paused, $campaign->fresh()->status);

        $this->actingAs($this->admin)->withRecentAuthentication()
            ->post("/staff/posts/{$campaign->post_id}/campaign/resume")
            ->assertRedirect();

        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);
        $this->assertNull($campaign->fresh()->pause_reason);
        Queue::assertPushed(SendCampaignRecipientJob::class, fn (SendCampaignRecipientJob $job) => $job->recipientId === $recipient->id);
        $this->assertTrue(AuditLog::query()->where('action', 'campaign.resumed')->where('actor_id', $this->admin->id)->exists());

        $this->sendTo($recipient->id);
        $this->assertSame(CampaignRecipientStatus::Accepted, $recipient->fresh()->status);
        $this->assertSame('resumed@mailgun.test', $recipient->fresh()->provider_message_id);
        $this->assertSame(CampaignStatus::Completed, $campaign->fresh()->status);
    }

    public function test_queued_sends_check_the_snapshot_sender_not_only_the_current_one(): void
    {
        Http::fake();
        $campaign = $this->campaign();
        $recipient = $campaign->recipients()->firstOrFail();
        $snapshot = $campaign->snapshot;
        $snapshot['sender']['address'] = 'news@apesshelter.org.uk';
        $campaign->update(['snapshot' => $snapshot]);
        EmailSendingDomain::query()->where('domain', 'apesshelter.org.uk')->update(['status' => SendingDomainStatus::Disabled]);

        $this->sendTo($recipient->id);

        $this->assertSame(CampaignRecipientStatus::Skipped, $recipient->fresh()->status);
        $this->assertSame(CampaignService::FAILURE_SENDER_UNVERIFIED, $recipient->fresh()->last_error);
        Http::assertNothingSent();
    }
}
