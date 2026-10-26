<?php

namespace Tests\Feature;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendCampaignRecipientJob;
use App\Mail\CampaignPostSummaryMail;
use App\Models\ApiToken;
use App\Models\Campaign;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\User;
use App\Notifications\ConfirmMailingListNotification;
use App\Services\Mail\DnsTxtResolver;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

class NewsletterSenderTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesNewsletterSenders;

    private function newsletter(string $slug): Newsletter
    {
        return Newsletter::query()->where('slug', $slug)->firstOrFail();
    }

    private function confirmedReader(MailingList $list, string $email = 'reader@example.com'): void
    {
        $contact = MailingContact::query()->firstOrCreate(['email' => $email]);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'list' => $list,
            'newsletter_id' => Newsletter::query()->where('legacy_list', $list->value)->value('id'),
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $lists
     */
    private function emailPost(User $author, array $lists, array $attributes = []): Post
    {
        return Post::factory()->create([
            'author_id' => $author->id,
            'status' => PostStatus::Draft,
            'email_on_publish' => true,
            'mailing_lists' => $lists,
            ...$attributes,
        ]);
    }

    private function fakeMailgun(string $state = 'active', bool $spf = true, bool $dkim = true): void
    {
        config(['services.mailgun.secret' => 'test-key', 'services.mailgun.domain' => null]);

        Http::fake([
            'api.eu.mailgun.net/v4/domains/*' => Http::response([
                'domain' => ['name' => 'apesnews.org.uk', 'state' => $state],
                'sending_dns_records' => [
                    ['record_type' => 'TXT', 'name' => 'apesnews.org.uk', 'value' => 'v=spf1 include:mailgun.org ~all', 'valid' => $spf ? 'valid' : 'unknown'],
                    ['record_type' => 'TXT', 'name' => 'mx._domainkey.apesnews.org.uk', 'value' => 'k=rsa; p=abc', 'valid' => $dkim ? 'valid' : 'unknown'],
                    ['record_type' => 'CNAME', 'name' => 'email.apesnews.org.uk', 'value' => 'eu.mailgun.org', 'valid' => 'valid'],
                ],
            ]),
        ]);
    }

    private function fakeDmarc(bool $present): void
    {
        $this->app->instance(DnsTxtResolver::class, new class($present) extends DnsTxtResolver
        {
            public function __construct(private readonly bool $present) {}

            public function txt(string $host): array
            {
                return $this->present ? ['v=DMARC1; p=quarantine'] : [];
            }
        });
    }

    public function test_live_campaign_uses_first_selected_newsletter_sender_and_reply_to(): void
    {
        Mail::fake();
        $this->verifyNewsletterSenders();
        $this->confirmedReader(MailingList::ApesShelterRescue);

        $admin = User::factory()->admin()->create();
        $post = $this->emailPost($admin, [MailingList::ApesPetCareClinic->value, MailingList::ApesShelterRescue->value]);

        $this->actingAs($admin)->post("/staff/posts/{$post->id}/publish")->assertRedirect()->assertSessionHasNoErrors();

        $campaign = Campaign::query()->where('post_id', $post->id)->firstOrFail();
        $this->assertSame($this->newsletter('apes-shelter-rescue')->id, $campaign->newsletter_id);
        $this->assertSame('shelterandrescue@apesnews.org.uk', $campaign->snapshot['sender']['address']);

        Mail::assertSent(CampaignPostSummaryMail::class, fn (CampaignPostSummaryMail $mail) => $mail->hasFrom('shelterandrescue@apesnews.org.uk', 'APES Shelter & Rescue')
            && $mail->hasReplyTo('info@apes.org.uk'));
    }

    public function test_staff_publish_with_email_is_blocked_for_unverified_sender(): void
    {
        Mail::fake();
        $admin = User::factory()->admin()->create();
        $post = $this->emailPost($admin, [MailingList::ApesCic->value]);

        $this->actingAs($admin)->post("/staff/posts/{$post->id}/publish")->assertSessionHasErrors('email_on_publish');

        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
        $this->assertSame(0, Campaign::query()->count());
        Mail::assertNothingSent();
    }

    public function test_staff_publish_without_email_is_not_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $post = $this->emailPost($admin, [MailingList::ApesCic->value], ['email_on_publish' => false]);

        $this->actingAs($admin)->post("/staff/posts/{$post->id}/publish")->assertSessionHasNoErrors();

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
    }

    public function test_admin_api_publish_with_email_is_blocked_for_unverified_sender(): void
    {
        $admin = User::factory()->admin()->create();
        $post = $this->emailPost($admin, [MailingList::ApesCic->value]);

        $this->withToken(ApiToken::issue($admin, 'test')['token'])
            ->postJson('/api/admin/v1/posts/'.$post->id.'/publish')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email_on_publish');

        $this->assertSame(PostStatus::Draft, $post->fresh()->status);
    }

    public function test_scheduler_publishes_but_records_failed_campaign_for_unverified_sender(): void
    {
        Mail::fake();
        $this->confirmedReader(MailingList::ApesCic);
        $admin = User::factory()->admin()->create();
        $post = $this->emailPost($admin, [MailingList::ApesCic->value], [
            'status' => PostStatus::Scheduled,
            'scheduled_for' => now('Europe/London')->subMinute(),
        ]);

        $this->artisan('posts:publish-scheduled')->assertSuccessful();

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
        $campaign = Campaign::query()->where('post_id', $post->id)->firstOrFail();
        $this->assertSame(CampaignStatus::Failed, $campaign->status);
        $this->assertSame(CampaignService::FAILURE_SENDER_UNVERIFIED, $campaign->failure_reason);
        $this->assertSame(0, $campaign->recipients()->count());
        Mail::assertNothingSent();
    }

    public function test_job_skips_recipients_when_sender_verification_is_revoked(): void
    {
        Mail::fake();
        $this->verifyNewsletterSenders();
        $this->confirmedReader(MailingList::ApesCic);
        $admin = User::factory()->admin()->create();
        $post = $this->emailPost($admin, [MailingList::ApesCic->value], ['status' => PostStatus::Published, 'published_at' => now()]);

        $queue = config('queue.default');
        config(['queue.default' => 'null']);
        $campaign = app(CampaignService::class)->createFromPublishedPost($post, $admin);
        config(['queue.default' => $queue]);

        $this->newsletter('apes-cic')->forceFill(['sender_verification' => null])->save();

        $recipient = $campaign->recipients()->firstOrFail();
        (new SendCampaignRecipientJob($recipient->id))->handle(app(ConsentService::class), app(CampaignSender::class));

        $this->assertSame(CampaignRecipientStatus::Skipped, $recipient->fresh()->status);
        $this->assertSame(CampaignService::FAILURE_SENDER_UNVERIFIED, $recipient->fresh()->last_error);
        Mail::assertNothingSent();
    }

    public function test_test_send_is_blocked_for_unverified_sender(): void
    {
        $admin = User::factory()->admin()->create();
        $post = $this->emailPost($admin, [MailingList::ApesCic->value]);

        $this->expectException(ValidationException::class);
        app(CampaignService::class)->createTestSend($post, $admin, 'editor@example.com');
    }

    public function test_confirmation_email_uses_verified_newsletter_sender_only(): void
    {
        Notification::fake();
        $consent = app(ConsentService::class);

        $consent->signup('first@example.com', [MailingList::ApesCic->value], 'test');
        $first = MailingContact::query()->where('email', 'first@example.com')->firstOrFail();
        Notification::assertSentTo($first, ConfirmMailingListNotification::class, fn ($notification) => $notification->toMail($first)->from === []);

        $this->markSenderVerified($this->newsletter('apes-cic'));
        $consent->signup('second@example.com', [MailingList::ApesCic->value], 'test');
        $second = MailingContact::query()->where('email', 'second@example.com')->firstOrFail();
        Notification::assertSentTo($second, ConfirmMailingListNotification::class, function ($notification) use ($second) {
            $mail = $notification->toMail($second);

            return $mail->from === ['app@apesnews.org.uk', 'APES Newsroom'] && $mail->replyTo === [['info@apes.org.uk', null]];
        });
    }

    public function test_verify_command_marks_senders_verified_when_mailgun_and_dmarc_pass(): void
    {
        $this->fakeMailgun();
        $this->fakeDmarc(true);

        $this->artisan('mailing:verify-senders')->assertSuccessful();

        foreach (Newsletter::query()->active()->get() as $newsletter) {
            $this->assertTrue(app(CampaignSender::class)->isVerified($newsletter), $newsletter->slug);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.eu.mailgun.net/v4/domains/apesnews.org.uk');
    }

    public function test_verify_command_reports_failing_checks_and_keeps_senders_blocked(): void
    {
        $this->fakeMailgun(dkim: false);
        $this->fakeDmarc(false);

        $this->artisan('mailing:verify-senders', ['--newsletter' => 'apes-times'])->assertFailed();

        $times = $this->newsletter('apes-times');
        $this->assertFalse(app(CampaignSender::class)->isVerified($times));
        $this->assertSame('unverified', $times->sender_verification['status']);
        $this->assertStringContainsString('DKIM', $times->sender_verification['message']);
        $this->assertStringContainsString('DMARC', $times->sender_verification['message']);
    }

    public function test_verify_command_without_mailgun_key_records_unknown(): void
    {
        Http::fake();
        config(['services.mailgun.secret' => null]);

        $this->artisan('mailing:verify-senders')->assertFailed();

        $this->assertSame('unknown', $this->newsletter('apes-cic')->sender_verification['status']);
        Http::assertNothingSent();
    }

    public function test_staff_can_recheck_sender_and_changing_sender_email_resets_verification(): void
    {
        $this->fakeMailgun();
        $this->fakeDmarc(true);
        $staff = User::factory()->staff()->create();
        $times = $this->newsletter('apes-times');

        $this->actingAs($staff)->post("/staff/newsletters/{$times->id}/verify-sender")->assertRedirect();
        $this->assertTrue(app(CampaignSender::class)->isVerified($times->fresh()));

        $this->actingAs($staff)->patch("/staff/newsletters/{$times->id}", [
            'name' => $times->name,
            'sender_name' => 'APES Times',
            'sender_email' => 'news@apesnews.org.uk',
            'reply_to' => 'info@apes.org.uk',
            'subscribe_on_signup' => true,
        ])->assertSessionHasNoErrors();

        $this->assertNull($times->fresh()->sender_verification);
        $this->assertFalse(app(CampaignSender::class)->isVerified($times->fresh()));
    }
}
