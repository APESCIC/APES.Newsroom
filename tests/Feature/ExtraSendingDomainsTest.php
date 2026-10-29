<?php

namespace Tests\Feature;

use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Enums\SendingDomainStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendCampaignRecipientJob;
use App\Mail\CampaignPostSummaryMail;
use App\Mail\SendingDomainTestMail;
use App\Models\AuditLog;
use App\Models\EmailSenderAddress;
use App\Models\EmailSendingDomain;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\User;
use App\Notifications\NewsletterSenderMessage;
use App\Services\Mail\DnsTxtResolver;
use App\Services\Mail\MailgunDomainService;
use App\Services\Mail\MailgunSenderCheck;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\ConsentService;
use App\Services\Mailing\DefaultReplyTo;
use App\Services\Mailing\SenderDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

class ExtraSendingDomainsTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesNewsletterSenders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function domain(string $name): EmailSendingDomain
    {
        return EmailSendingDomain::query()->where('domain', $name)->firstOrFail();
    }

    private function address(string $address): EmailSenderAddress
    {
        return EmailSenderAddress::query()->where('address', $address)->firstOrFail();
    }

    /**
     * A domain whose Mailgun domain is a subdomain and whose only From address
     * is on that subdomain, as strict DMARC requires.
     */
    private function subdomainFixture(): EmailSendingDomain
    {
        $domain = EmailSendingDomain::query()->create([
            'domain' => 'example.net',
            'mailgun_domain' => 'news.example.net',
            'status' => SendingDomainStatus::Pending,
        ]);
        $domain->addresses()->create(['address' => 'news@news.example.net', 'purpose' => 'news']);

        return $domain;
    }

    private function myapesNewsletter(): Newsletter
    {
        return Newsletter::query()->where('slug', 'myapes')->firstOrFail();
    }

    /**
     * Fakes the Mailgun API: domain lookups report active with valid records,
     * and messages.mime accepts or refuses the message.
     */
    private function fakeMailgun(bool $sendOk = true, string $state = 'active'): void
    {
        config(['services.mailgun.secret' => 'test-key', 'services.mailgun.domain' => null]);

        Http::fake([
            'api.eu.mailgun.net/v3/*' => $sendOk
                ? Http::response(['id' => '<20261029.abc123@mailgun.test>', 'message' => 'Queued. Thank you.'])
                : Http::response(['message' => 'Domain mail.apespetcare.org.uk is not allowed to send: Free accounts are for test purposes only.'], 403),
            'api.eu.mailgun.net/v4/domains/*' => Http::response([
                'domain' => ['state' => $state],
                'sending_dns_records' => [
                    ['record_type' => 'TXT', 'name' => 'x', 'value' => 'v=spf1 include:mailgun.org ~all', 'valid' => 'valid'],
                    ['record_type' => 'TXT', 'name' => 'mx._domainkey.x', 'value' => 'k=rsa; p=abc', 'valid' => 'valid'],
                ],
                'receiving_dns_records' => [],
            ]),
        ]);

        $this->app->instance(DnsTxtResolver::class, new class extends DnsTxtResolver
        {
            /** Only the organisational domains publish DMARC, like myapes.me.uk. */
            public function txt(string $host): array
            {
                return str_starts_with($host, '_dmarc.news.') ? [] : ['v=DMARC1; p=quarantine'];
            }
        });
    }

    private function sendFrom(string $from): void
    {
        Mail::mailer('mailgun-api')->to('reader@example.com')->send(new SendingDomainTestMail($from, 'unused', 'Test'));
    }

    private function assertMessageSentThrough(string $mailgunDomain): void
    {
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === "https://api.eu.mailgun.net/v3/{$mailgunDomain}/messages.mime"
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('api:test-key')));
    }

    public function test_extra_domains_and_from_addresses_are_seeded_pending(): void
    {
        $this->assertSame('mail.apespetcare.org.uk', $this->domain('apespetcare.org.uk')->mailgun_domain);
        $this->assertSame('mail.apesshelter.org.uk', $this->domain('apesshelter.org.uk')->mailgun_domain);
        $this->assertSame('myapes.me.uk', $this->domain('myapes.me.uk')->mailgun_domain);
        foreach (['apespetcare.org.uk', 'apesshelter.org.uk', 'myapes.me.uk'] as $name) {
            $this->assertSame(SendingDomainStatus::Pending, $this->domain($name)->status);
        }

        $this->assertSame(['news@myapes.me.uk'], $this->domain('myapes.me.uk')->addresses->pluck('address')->all());
        $this->assertSame(['news@apespetcare.org.uk', 'noreply@apespetcare.org.uk'], $this->domain('apespetcare.org.uk')->addresses->pluck('address')->all());
        $this->assertSame(
            Newsletter::query()->whereNotNull('sender_email')->orderBy('sender_email')->pluck('sender_email')->all(),
            $this->domain('apesnews.org.uk')->addresses->pluck('address')->all(),
        );

        $seed = require database_path('migrations/2026_11_01_100100_seed_extra_sending_domains.php');
        $root = require database_path('migrations/2026_11_03_100000_move_myapes_to_root_mailgun_domain.php');
        $seed->up();
        $root->up();
        $this->assertSame(5, EmailSendingDomain::query()->count());
        $this->assertSame(11, EmailSenderAddress::query()->count());
        $this->assertFalse(EmailSenderAddress::query()->where('address', 'news@news.myapes.me.uk')->exists());
    }

    public function test_myapes_moves_to_its_root_mailgun_domain_and_back(): void
    {
        $domain = $this->domain('myapes.me.uk');
        $domain->forceFill(['status' => SendingDomainStatus::Verified, 'verified_at' => now()])->save();
        $migration = require database_path('migrations/2026_11_03_100000_move_myapes_to_root_mailgun_domain.php');

        $migration->down();
        $this->assertSame('news.myapes.me.uk', $domain->fresh()->mailgun_domain);
        $this->assertSame(SendingDomainStatus::Pending, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->verified_at);
        $this->assertSame(['news@news.myapes.me.uk'], $domain->fresh()->addresses->pluck('address')->all());

        $this->myapesNewsletter()->forceFill(['sender_email' => 'news@news.myapes.me.uk'])->save();
        $migration->up();
        $this->assertSame('myapes.me.uk', $domain->fresh()->mailgun_domain);
        $this->assertSame(['news@myapes.me.uk', 'news@news.myapes.me.uk'], $domain->fresh()->addresses->pluck('address')->all());

        $admin = User::factory()->admin()->create();
        $domain->fresh()->forceFill(['created_by' => $admin->id])->save();
        $migration->down();
        $this->assertSame('myapes.me.uk', $domain->fresh()->mailgun_domain);
    }

    public function test_each_from_domain_sends_through_its_own_mailgun_domain(): void
    {
        $this->fakeMailgun();
        $this->subdomainFixture();

        $routes = [
            'app@apesnews.org.uk' => 'apesnews.org.uk',
            'news@apespetcare.org.uk' => 'mail.apespetcare.org.uk',
            'noreply@apesshelter.org.uk' => 'mail.apesshelter.org.uk',
            'news@myapes.me.uk' => 'myapes.me.uk',
            'news@news.example.net' => 'news.example.net',
        ];

        foreach ($routes as $from => $mailgunDomain) {
            $this->assertSame($mailgunDomain, SenderDomains::mailgunDomainForSender($from, app(MailgunDomainService::class)), $from);
            $this->sendFrom($from);
            $this->assertMessageSentThrough($mailgunDomain);
        }

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v3/myapes.me.uk/')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'to' && $part['contents'] === 'reader@example.com'));
    }

    public function test_a_configured_mailgun_subdomain_is_used_for_its_parent_domain(): void
    {
        $this->fakeMailgun();
        config(['services.mailgun.domain' => 'mg.apesnews.org.uk']);

        $this->sendFrom('app@apesnews.org.uk');

        $this->assertMessageSentThrough('mg.apesnews.org.uk');
    }

    public function test_unapproved_and_disabled_domains_are_refused_without_calling_mailgun(): void
    {
        $this->fakeMailgun();

        try {
            $this->sendFrom('news@example.com');
            $this->fail('An unapproved From domain must be refused.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('not an approved, enabled sending domain', $exception->getMessage());
        }

        $this->domain('apesshelter.org.uk')->update(['status' => SendingDomainStatus::Disabled]);
        $this->expectException(TransportException::class);
        try {
            $this->sendFrom('news@apesshelter.org.uk');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_campaign_sends_use_the_mailgun_mailer_only_when_configured_and_enabled(): void
    {
        $this->assertNull(SenderDomains::newsletterMailer());

        config(['services.mailgun.secret' => 'test-key']);
        $this->assertSame('mailgun-api', SenderDomains::newsletterMailer());

        config(['mailing.newsletter_transport' => 'smtp']);
        $this->assertNull(SenderDomains::newsletterMailer());

        config(['mailing.newsletter_transport' => 'mailgun']);
        Mail::fake();
        $this->verifyNewsletterSenders();
        $contact = MailingContact::query()->create(['email' => 'reader@example.com']);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'list' => MailingList::ApesCic,
            'newsletter_id' => Newsletter::query()->where('legacy_list', MailingList::ApesCic->value)->value('id'),
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::Published,
            'published_at' => now(),
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        $queue = config('queue.default');
        config(['queue.default' => 'null']);
        $campaign = app(CampaignService::class)->createFromPublishedPost($post, $admin);
        config(['queue.default' => $queue]);

        $recipient = $campaign->recipients()->firstOrFail();
        (new SendCampaignRecipientJob($recipient->id))->handle(app(ConsentService::class), app(CampaignSender::class));

        Mail::assertSent(CampaignPostSummaryMail::class, fn (CampaignPostSummaryMail $mail) => $mail->mailer === 'mailgun-api' && $mail->hasTo('reader@example.com'));
    }

    public function test_sender_check_verifies_a_subdomain_from_address_through_its_mailgun_domain(): void
    {
        $this->fakeMailgun();
        $this->subdomainFixture();
        $newsletter = $this->myapesNewsletter();
        $newsletter->forceFill(['sender_email' => 'news@news.example.net'])->save();

        $result = app(MailgunSenderCheck::class)->check($newsletter);

        $this->assertSame(CampaignSender::VERIFIED, $result['status']);
        $this->assertSame('example.net', $result['domain']);
        $this->assertSame('news.example.net', $result['mailgun_domain']);
        $this->assertTrue($result['checks']['dmarc']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.eu.mailgun.net/v4/domains/news.example.net');
    }

    public function test_sender_check_verifies_the_myapes_root_address_through_the_root_mailgun_domain(): void
    {
        $this->fakeMailgun();
        $newsletter = $this->myapesNewsletter();
        $newsletter->forceFill(['sender_email' => 'news@myapes.me.uk'])->save();

        $result = app(MailgunSenderCheck::class)->check($newsletter);

        $this->assertSame(CampaignSender::VERIFIED, $result['status']);
        $this->assertSame('myapes.me.uk', $result['domain']);
        $this->assertSame('myapes.me.uk', $result['mailgun_domain']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.eu.mailgun.net/v4/domains/myapes.me.uk');
    }

    public function test_admin_sends_a_test_email_and_sees_the_message_id_and_live_checks(): void
    {
        $this->fakeMailgun();
        DefaultReplyTo::set('info@apes.org.uk');
        $admin = User::factory()->admin()->create(['email' => 'admin@apes.org.uk']);
        $address = $this->address('news@apespetcare.org.uk');

        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson("/admin/settings/email-domains/addresses/{$address->id}/test", ['extra_recipient' => 'Volunteer@Example.com'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'from' => 'news@apespetcare.org.uk',
                'recipients' => ['admin@apes.org.uk', 'volunteer@example.com'],
                'mailgun_domain' => 'mail.apespetcare.org.uk',
                'message_id' => '20261029.abc123@mailgun.test',
                'error' => null,
                'status' => 'verified',
                'checks' => ['mailgun_state' => 'active', 'spf' => true, 'dkim' => true, 'dmarc' => true],
            ]);

        $this->assertMessageSentThrough('mail.apespetcare.org.uk');
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages.mime')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'to' && $part['contents'] === 'admin@apes.org.uk,volunteer@example.com')
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'message'
                && str_contains($part['contents'], 'Reply-To: info@apes.org.uk')
                && ! str_contains($part['contents'], 'volunteer@example.com')));

        $address->refresh();
        $this->assertTrue($address->last_test_result['ok']);
        $this->assertNotNull($address->last_tested_at);
        $this->assertNotNull($this->domain('apespetcare.org.uk')->last_tested_at);

        $audit = AuditLog::query()->where('action', 'email_sending_domain.test_sent')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('sent', $audit->payload['outcome']);
        $this->assertSame(['admin@apes.org.uk', 'volunteer@example.com'], $audit->payload['recipients']);
        $this->assertSame($address->getMorphClass(), $audit->subject_type);
    }

    public function test_a_failed_test_send_shows_the_provider_error_and_is_audited(): void
    {
        $this->fakeMailgun(sendOk: false);
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('apespetcare.org.uk');

        $response = $this->actingAs($admin)->withRecentAuthentication()
            ->postJson("/admin/settings/email-domains/{$domain->id}/test")
            ->assertOk()
            ->assertJson(['ok' => false, 'from' => 'noreply@apespetcare.org.uk', 'message_id' => null]);

        $this->assertStringContainsString('HTTP 403', $response->json('error'));
        $this->assertStringContainsString('Free accounts are for test purposes only', $response->json('error'));
        $this->assertFalse($domain->fresh()->last_test_result['ok']);
        $this->assertSame('failed', AuditLog::query()->where('action', 'email_sending_domain.test_sent')->firstOrFail()->payload['outcome']);
    }

    public function test_an_unverified_domain_warns_but_still_attempts_the_test(): void
    {
        $this->fakeMailgun(state: 'unverified');
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('myapes.me.uk');

        $response = $this->actingAs($admin)->withRecentAuthentication()
            ->postJson("/admin/settings/email-domains/{$domain->id}/test")
            ->assertOk()
            ->assertJson(['ok' => true, 'from' => 'news@myapes.me.uk', 'status' => 'failed']);

        $this->assertStringContainsString('is not verified', $response->json('warning'));
        $this->assertMessageSentThrough('myapes.me.uk');
    }

    public function test_test_send_reports_missing_mailgun_configuration_without_sending(): void
    {
        config(['services.mailgun.secret' => null]);
        Http::fake();
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('apesshelter.org.uk');

        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson("/admin/settings/email-domains/{$domain->id}/test")
            ->assertOk()
            ->assertJson(['ok' => false, 'error' => 'The Mailgun API key is not configured under Integrations, so no test could be sent.']);

        Http::assertNothingSent();
    }

    public function test_staff_cannot_manage_addresses_or_send_tests(): void
    {
        $this->fakeMailgun();
        $staff = User::factory()->staff()->create();
        $domain = $this->domain('apespetcare.org.uk');
        $address = $this->address('news@apespetcare.org.uk');

        $this->actingAs($staff)->withRecentAuthentication()->postJson("/admin/settings/email-domains/{$domain->id}/test")->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()->postJson("/admin/settings/email-domains/addresses/{$address->id}/test")->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()
            ->post("/admin/settings/email-domains/{$domain->id}/addresses", ['address' => 'desk@apespetcare.org.uk', 'purpose' => 'news'])
            ->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()->post("/admin/settings/email-domains/addresses/{$address->id}/disable")->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()->put('/admin/settings/email-domains/default-reply-to', ['default_reply_to' => 'info@apes.org.uk'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_test_sends_and_address_changes_require_recent_authentication(): void
    {
        $this->fakeMailgun();
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('apespetcare.org.uk');

        $this->actingAs($admin)->postJson("/admin/settings/email-domains/{$domain->id}/test")->assertStatus(423);
        $this->actingAs($admin)->post("/admin/settings/email-domains/{$domain->id}/addresses", ['address' => 'desk@apespetcare.org.uk', 'purpose' => 'news'])
            ->assertRedirect('/account/confirm-identity');

        $this->assertFalse(EmailSenderAddress::query()->where('address', 'desk@apespetcare.org.uk')->exists());
        Http::assertNothingSent();
    }

    public function test_the_sixth_test_in_a_minute_is_refused_with_a_friendly_429(): void
    {
        $this->fakeMailgun();
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('apespetcare.org.uk');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($admin)->withRecentAuthentication()->postJson("/admin/settings/email-domains/{$domain->id}/test")->assertOk();
        }

        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson("/admin/settings/email-domains/{$domain->id}/test", ['extra_recipient' => 'someone@example.com'])
            ->assertStatus(429)
            ->assertJson(['message' => 'You have sent several test emails in the last minute. Please wait a minute and try again.']);

        $this->assertSame(5, AuditLog::query()->where('action', 'email_sending_domain.test_sent')->count());
    }

    public function test_each_domain_has_a_daily_test_limit_shared_by_its_addresses(): void
    {
        $this->fakeMailgun();
        config(['mailing.test_send.per_domain_per_day' => 2]);
        $first = User::factory()->admin()->create();
        $second = User::factory()->admin()->create();
        $domain = $this->domain('apesshelter.org.uk');
        $address = $this->address('news@apesshelter.org.uk');

        $this->actingAs($first)->withRecentAuthentication()->postJson("/admin/settings/email-domains/{$domain->id}/test")->assertOk();
        $this->actingAs($second)->withRecentAuthentication()->postJson("/admin/settings/email-domains/addresses/{$address->id}/test")->assertOk();
        $this->actingAs($second)->withRecentAuthentication()
            ->postJson("/admin/settings/email-domains/addresses/{$address->id}/test")
            ->assertStatus(429)
            ->assertJson(['message' => 'This domain has reached its limit of 2 test emails in 24 hours. You can send another in 1 day.']);

        $other = $this->domain('apespetcare.org.uk');
        $this->actingAs($second)->withRecentAuthentication()->postJson("/admin/settings/email-domains/{$other->id}/test")->assertOk();
    }

    public function test_refused_test_requests_do_not_use_up_the_daily_limit(): void
    {
        $this->fakeMailgun();
        config(['mailing.test_send.per_domain_per_day' => 1]);
        $domain = $this->domain('apespetcare.org.uk');
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson("/admin/settings/email-domains/{$domain->id}/test")->assertStatus(423);
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($staff)->withRecentAuthentication()->postJson("/admin/settings/email-domains/{$domain->id}/test")->assertForbidden();
        }
        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson("/admin/settings/email-domains/{$domain->id}/test", ['extra_recipient' => 'not-an-email'])
            ->assertStatus(422);

        $this->actingAs($admin)->withRecentAuthentication()->postJson("/admin/settings/email-domains/{$domain->id}/test")->assertOk();
    }

    public function test_disabled_domains_and_addresses_cannot_be_tested(): void
    {
        $this->fakeMailgun();
        $admin = User::factory()->admin()->create();
        $address = $this->address('news@apesshelter.org.uk');
        $address->update(['enabled' => false]);

        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson("/admin/settings/email-domains/addresses/{$address->id}/test")
            ->assertStatus(422)
            ->assertJson(['message' => 'news@apesshelter.org.uk is disabled. Enable it before sending a test.']);

        $domain = $this->domain('apespetcare.org.uk');
        $domain->update(['status' => SendingDomainStatus::Disabled]);
        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson("/admin/settings/email-domains/{$domain->id}/test")
            ->assertStatus(422)
            ->assertJson(['message' => 'apespetcare.org.uk is disabled. Enable it before sending a test.']);

        Http::assertNothingSent();
    }

    public function test_confirmation_emails_from_a_new_domain_go_through_its_mailgun_domain(): void
    {
        $this->fakeMailgun();
        $this->markSendingDomainVerified('apespetcare.org.uk');
        $newsletter = Newsletter::query()->whereNotNull('sender_email')->firstOrFail();
        $newsletter->forceFill(['sender_email' => 'news@apespetcare.org.uk', 'reply_to' => null])->save();
        $this->markSenderVerified($newsletter);
        $this->assertTrue(app(CampaignSender::class)->isVerified($newsletter->fresh()));

        $message = NewsletterSenderMessage::apply(new MailMessage, $newsletter->fresh());

        $this->assertSame('mailgun-api', $message->mailer);
        $this->assertSame('news@apespetcare.org.uk', $message->from[0]);

        config(['mailing.newsletter_transport' => 'smtp']);
        $this->assertNull(NewsletterSenderMessage::apply(new MailMessage, $newsletter->fresh())->mailer);
    }

    public function test_strict_dmarc_requires_the_from_domain_to_be_the_mailgun_domain(): void
    {
        $this->fakeMailgun();
        $this->subdomainFixture();
        $this->app->instance(DnsTxtResolver::class, new class extends DnsTxtResolver
        {
            public function txt(string $host): array
            {
                return in_array($host, ['_dmarc.example.net', '_dmarc.myapes.me.uk'], true) ? ['v=DMARC1; p=quarantine; adkim=s; aspf=s'] : [];
            }
        });
        $newsletter = $this->myapesNewsletter();

        $newsletter->forceFill(['sender_email' => 'news@example.net'])->save();
        $root = app(MailgunSenderCheck::class)->check($newsletter);
        $this->assertSame(MailgunSenderCheck::UNVERIFIED, $root['status']);
        $this->assertFalse($root['checks']['dmarc']);
        $this->assertStringContainsString('must end with @news.example.net', $root['message']);

        $newsletter->forceFill(['sender_email' => 'news@news.example.net'])->save();
        $this->assertSame(CampaignSender::VERIFIED, app(MailgunSenderCheck::class)->check($newsletter)['status']);

        $newsletter->forceFill(['sender_email' => 'news@myapes.me.uk'])->save();
        $this->assertSame(CampaignSender::VERIFIED, app(MailgunSenderCheck::class)->check($newsletter)['status']);
    }

    public function test_renaming_a_from_address_in_use_is_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $this->myapesNewsletter()->forceFill(['sender_email' => 'news@myapes.me.uk'])->save();
        $address = $this->address('news@myapes.me.uk');

        $this->actingAs($admin)->withRecentAuthentication()
            ->put("/admin/settings/email-domains/addresses/{$address->id}", ['address' => 'hello@myapes.me.uk', 'purpose' => 'news'])
            ->assertSessionHasErrors('address');
        $this->assertSame('news@myapes.me.uk', $address->fresh()->address);

        $this->actingAs($admin)->withRecentAuthentication()
            ->put("/admin/settings/email-domains/addresses/{$address->id}", ['address' => 'news@myapes.me.uk', 'purpose' => 'news', 'label' => 'MyAPES'])
            ->assertSessionHasNoErrors();
        $this->assertSame('MyAPES', $address->fresh()->label);
    }

    public function test_mailgun_domains_must_be_unique_and_no_reply_addresses_are_not_newsletter_senders(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withRecentAuthentication()
            ->post('/admin/settings/email-domains', ['domain' => 'example.org.uk', 'mailgun_domain' => 'mail.apespetcare.org.uk'])
            ->assertSessionHasErrors(['mailgun_domain' => 'That Mailgun domain is already used by another sending domain.']);
        $this->actingAs($admin)->withRecentAuthentication()
            ->post('/admin/settings/email-domains', ['domain' => 'mail.apesshelter.org.uk'])
            ->assertSessionHasErrors('domain');

        $this->assertStringContainsString('is a no-reply address', (string) SenderDomains::senderAddressProblem('noreply@apespetcare.org.uk'));
        $this->assertNull(SenderDomains::senderAddressProblem('news@apespetcare.org.uk'));
    }

    public function test_seed_migration_rollback_keeps_domains_an_admin_added(): void
    {
        $admin = User::factory()->admin()->create();
        $this->domain('apesshelter.org.uk')->forceFill(['created_by' => $admin->id])->save();

        $migration = require database_path('migrations/2026_11_01_100100_seed_extra_sending_domains.php');
        $migration->down();

        $this->assertFalse(EmailSendingDomain::query()->where('domain', 'apespetcare.org.uk')->exists());
        $this->assertTrue(EmailSendingDomain::query()->where('domain', 'apesshelter.org.uk')->exists());
    }

    public function test_admin_adds_edits_disables_and_enables_from_addresses_with_audit(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('myapes.me.uk');
        $post = fn (array $data) => $this->actingAs($admin)->withRecentAuthentication()->post("/admin/settings/email-domains/{$domain->id}/addresses", $data);

        $post(['address' => 'news@example.com', 'purpose' => 'news'])->assertSessionHasErrors(['address' => 'The address must end with @myapes.me.uk.']);
        $post(['address' => 'news@news.myapes.me.uk', 'purpose' => 'news'])->assertSessionHasErrors('address');
        $post(['address' => 'postmaster@myapes.me.uk', 'purpose' => 'news'])->assertSessionHasErrors('address');
        $post(['address' => 'news@myapes.me.uk', 'purpose' => 'news'])->assertSessionHasErrors(['address' => 'That address is already on the list.']);
        $post(['address' => 'desk@myapes.me.uk', 'purpose' => 'spam'])->assertSessionHasErrors('purpose');

        $post(['address' => ' Updates@MyAPES.me.uk ', 'purpose' => 'noreply', 'label' => 'Account updates'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('offer_test_address');

        $address = $this->address('updates@myapes.me.uk');
        $this->assertSame($admin->id, $address->created_by);
        $this->assertSame(['domain' => 'myapes.me.uk', 'address' => 'updates@myapes.me.uk', 'purpose' => 'noreply'], array_intersect_key(
            AuditLog::query()->where('action', 'email_sender_address.added')->firstOrFail()->payload,
            array_flip(['address', 'purpose', 'domain']),
        ));

        $this->actingAs($admin)->withRecentAuthentication()
            ->put("/admin/settings/email-domains/addresses/{$address->id}", ['address' => 'updates@myapes.me.uk', 'purpose' => 'news', 'label' => ''])
            ->assertSessionHasNoErrors();
        $updated = AuditLog::query()->where('action', 'email_sender_address.updated')->firstOrFail();
        $this->assertSame([
            'purpose' => ['from' => 'noreply', 'to' => 'news'],
            'label' => ['from' => 'Account updates', 'to' => null],
        ], $updated->payload['changes']);

        $this->actingAs($admin)->withRecentAuthentication()->post("/admin/settings/email-domains/addresses/{$address->id}/disable");
        $this->assertFalse($address->fresh()->enabled);
        $this->actingAs($admin)->withRecentAuthentication()->post("/admin/settings/email-domains/addresses/{$address->id}/enable");
        $this->assertTrue($address->fresh()->enabled);
        $this->assertSame(1, AuditLog::query()->where('action', 'email_sender_address.disabled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'email_sender_address.enabled')->count());

        $this->actingAs($admin)->get('/admin/settings/email-domains')
            ->assertInertia(fn (Assert $page) => $page->where('domains.4.addresses.1.address', 'updates@myapes.me.uk'));
    }

    public function test_default_reply_to_must_be_on_an_enabled_domain_and_fills_in_for_newsletters(): void
    {
        $admin = User::factory()->admin()->create();
        $put = fn (string $value) => $this->actingAs($admin)->withRecentAuthentication()
            ->put('/admin/settings/email-domains/default-reply-to', ['default_reply_to' => $value]);

        $put('desk@example.com')->assertSessionHasErrors('default_reply_to');
        $put(' Newsroom@APES.org.uk ')->assertSessionHasNoErrors();
        $this->assertSame('newsroom@apes.org.uk', DefaultReplyTo::get());
        $this->assertSame(['from' => null, 'to' => 'newsroom@apes.org.uk'], array_intersect_key(
            AuditLog::query()->where('action', 'email_sending_domain.default_reply_to_changed')->firstOrFail()->payload,
            array_flip(['from', 'to']),
        ));

        $times = Newsletter::query()->where('slug', 'apes-times')->firstOrFail();
        $times->forceFill(['reply_to' => null])->save();
        $this->assertSame('newsroom@apes.org.uk', app(CampaignSender::class)->identity($times)['reply_to']);

        $this->domain('apes.org.uk')->update(['status' => SendingDomainStatus::Disabled]);
        $this->assertNull(app(CampaignSender::class)->identity($times)['reply_to']);

        $put('')->assertSessionHasNoErrors();
        $this->assertNull(DefaultReplyTo::get());
    }
}
