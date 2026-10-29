<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Enums\SendingDomainStatus;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\EmailSenderAddress;
use App\Models\EmailSendingDomain;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\User;
use App\Services\Mail\DnsTxtResolver;
use App\Services\Mail\SendingDomainChecker;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\SenderDomains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

class EmailSendingDomainsTest extends TestCase
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

    private function newsletter(string $slug): Newsletter
    {
        return Newsletter::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $extra  Earlier patterns win, so per-domain overrides go first.
     */
    private function fakeMailgun(string $state = 'active', bool $spf = true, bool $dkim = true, int $status = 200, array $extra = []): void
    {
        config(['services.mailgun.secret' => 'test-key', 'services.mailgun.domain' => null]);

        Http::fake([
            ...$extra,
            'api.eu.mailgun.net/v4/domains/*' => $status === 200
                ? Http::response([
                    'domain' => ['name' => 'apesnews.org.uk', 'state' => $state],
                    'sending_dns_records' => [
                        ['record_type' => 'TXT', 'name' => 'apesnews.org.uk', 'value' => 'v=spf1 include:mailgun.org ~all', 'valid' => $spf ? 'valid' : 'unknown'],
                        ['record_type' => 'TXT', 'name' => 'mx._domainkey.apesnews.org.uk', 'value' => 'k=rsa; p=abc', 'valid' => $dkim ? 'valid' : 'unknown'],
                        ['record_type' => 'CNAME', 'name' => 'email.apesnews.org.uk', 'value' => 'eu.mailgun.org', 'valid' => 'valid'],
                    ],
                    'receiving_dns_records' => [
                        ['record_type' => 'MX', 'priority' => '10', 'value' => 'mxa.eu.mailgun.org', 'valid' => 'valid'],
                    ],
                ])
                : Http::response(['message' => 'error'], $status),
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

    private function assertMailgunReadOnly(): void
    {
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    public function test_confirmed_domains_are_seeded_and_existing_senders_pass_once_verified(): void
    {
        $this->assertSame(['apes.org.uk', 'apesnews.org.uk', 'apespetcare.org.uk', 'apesshelter.org.uk', 'myapes.me.uk'], SenderDomains::allowed());
        $this->assertSame(SendingDomainStatus::Pending, $this->domain('apesnews.org.uk')->status);

        $this->markSendingDomainVerified('apesnews.org.uk');

        $newsletters = Newsletter::query()->whereNotNull('sender_email')->get();
        $this->assertCount(6, $newsletters);
        foreach ($newsletters as $newsletter) {
            $this->assertNull(SenderDomains::senderProblem($newsletter->sender_email), $newsletter->slug);
            $this->assertSame('info@apes.org.uk', $newsletter->reply_to);
            $this->assertNull(SenderDomains::replyToProblem($newsletter->reply_to), $newsletter->slug);
        }
    }

    public function test_seed_carries_over_an_existing_verified_sender_so_sends_are_not_interrupted(): void
    {
        EmailSendingDomain::query()->delete();
        $this->newsletter('apes-cic')->forceFill(['sender_verification' => [
            'status' => CampaignSender::VERIFIED,
            'sender_email' => 'app@apesnews.org.uk',
        ]])->save();

        $migration = require database_path('migrations/2026_10_29_100100_seed_email_sending_domains.php');
        $migration->up();
        $migration->up();

        $this->assertSame(2, EmailSendingDomain::query()->count());
        $this->assertSame(SendingDomainStatus::Verified, $this->domain('apesnews.org.uk')->status);
        $this->assertSame(SendingDomainStatus::Pending, $this->domain('apes.org.uk')->status);
    }

    public function test_staff_cannot_view_or_change_sending_domains(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/admin/settings/email-domains')->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()
            ->post('/admin/settings/email-domains', ['domain' => 'example.org'])
            ->assertForbidden();
    }

    public function test_admin_sees_domains_with_usage_and_settings_link(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/settings/email-domains')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/EmailDomains/Index')
                ->has('domains', 5)
                ->where('domains.0.domain', 'apes.org.uk')
                ->where('domains.0.in_use.0.uses', ['reply_to'])
                ->where('domains.1.domain', 'apesnews.org.uk')
                ->where('domains.1.in_use.0.uses', ['from'])
                ->has('domains.1.addresses', 6)
                ->where('domains.4.domain', 'myapes.me.uk')
                ->where('domains.4.mailgun_domain', 'news.myapes.me.uk')
                ->where('domains.4.addresses.0.address', 'news@news.myapes.me.uk')
                ->where('testRecipient', $admin->email));
    }

    public function test_adding_a_domain_validates_audits_and_only_reads_mailgun(): void
    {
        $this->fakeMailgun();
        $this->fakeDmarc(true);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->post('/admin/settings/email-domains', ['domain' => 'https://bad domain'])
            ->assertSessionHasErrors('domain');
        $this->actingAs($admin)->withRecentAuthentication()
            ->post('/admin/settings/email-domains', ['domain' => 'ApesNews.org.uk'])
            ->assertSessionHasErrors('domain');

        $this->actingAs($admin)->withRecentAuthentication()
            ->post('/admin/settings/email-domains', ['domain' => ' News.Example.ORG ', 'label' => 'Partner news'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $domain = $this->domain('news.example.org');
        $this->assertSame('Partner news', $domain->label);
        $this->assertSame($admin->id, $domain->created_by);
        $this->assertSame(SendingDomainStatus::Verified, $domain->status);
        $this->assertTrue(AuditLog::query()->where('action', 'email_sending_domain.added')->where('actor_id', $admin->id)->exists());
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://api.eu.mailgun.net/v4/domains/news.example.org');
        $this->assertMailgunReadOnly();
    }

    public function test_adding_a_domain_requires_recent_authentication(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/settings/email-domains', ['domain' => 'example.org'])->assertRedirect();

        $this->assertFalse(EmailSendingDomain::query()->where('domain', 'example.org')->exists());
    }

    public function test_check_verifies_domain_shows_records_and_audits_the_status_change(): void
    {
        $this->fakeMailgun();
        $this->fakeDmarc(true);
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('apesnews.org.uk');

        $this->actingAs($admin)->post("/admin/settings/email-domains/{$domain->id}/recheck")->assertRedirect();

        $domain->refresh();
        $this->assertSame(SendingDomainStatus::Verified, $domain->status);
        $this->assertNotNull($domain->verified_at);
        $this->assertSame(['SPF', 'DKIM', 'Tracking', 'MX'], array_column($domain->last_check['records'], 'purpose'));
        $this->assertTrue($domain->last_check['checks']['dmarc']);

        $audit = AuditLog::query()->where('action', 'email_sending_domain.status_changed')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(['domain' => 'apesnews.org.uk', 'old_status' => 'pending', 'new_status' => 'verified'], array_intersect_key($audit->payload, array_flip(['domain', 'old_status', 'new_status'])));
        $this->assertMailgunReadOnly();
    }

    public function test_failing_records_fail_the_domain_and_missing_domains_stay_pending(): void
    {
        $this->fakeMailgun(dkim: false, extra: [
            'api.eu.mailgun.net/v4/domains/apes.org.uk' => Http::response(['message' => 'Domain not found'], 404),
        ]);
        $this->fakeDmarc(false);
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('apesnews.org.uk');

        $this->actingAs($admin)->post("/admin/settings/email-domains/{$domain->id}/recheck");
        $domain->refresh();
        $this->assertSame(SendingDomainStatus::Failed, $domain->status);
        $this->assertStringContainsString('DKIM', $domain->last_check['message']);
        $this->assertStringContainsString('DMARC', $domain->last_check['message']);
        $this->assertSame(SendingDomainChecker::dmarcRecommendation(), $domain->last_check['dmarc']['recommendation']);

        $other = $this->domain('apes.org.uk');
        $this->actingAs($admin)->post("/admin/settings/email-domains/{$other->id}/recheck");
        $other->refresh();
        $this->assertSame(SendingDomainStatus::Pending, $other->status);
        $this->assertStringContainsString('not found in Mailgun', $other->last_check['message']);
    }

    public function test_mailgun_errors_are_shown_and_leave_the_status_unchanged(): void
    {
        $this->fakeMailgun(status: 500);
        $this->fakeDmarc(true);
        $admin = User::factory()->admin()->create();
        $domain = $this->markSendingDomainVerified('apesnews.org.uk');

        $this->actingAs($admin)->post("/admin/settings/email-domains/{$domain->id}/recheck")
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'HTTP 500'));

        $domain->refresh();
        $this->assertSame(SendingDomainStatus::Verified, $domain->status);
        $this->assertSame('Mailgun API returned HTTP 500.', $domain->last_check['error']);
    }

    public function test_a_domain_in_use_cannot_be_removed_but_can_be_disabled_and_enabled(): void
    {
        $this->fakeMailgun();
        $this->fakeDmarc(true);
        $admin = User::factory()->admin()->create();
        $domain = $this->domain('apes.org.uk');

        $this->actingAs($admin)->withRecentAuthentication()
            ->delete("/admin/settings/email-domains/{$domain->id}")
            ->assertSessionHasErrors('remove');
        $this->assertModelExists($domain);

        $this->actingAs($admin)->withRecentAuthentication()->post("/admin/settings/email-domains/{$domain->id}/disable");
        $this->assertSame(SendingDomainStatus::Disabled, $domain->fresh()->status);
        $disabled = AuditLog::query()->where('action', 'email_sending_domain.disabled')->firstOrFail();
        $this->assertSame('apes.org.uk', $disabled->payload['domain']);
        $this->assertSame('disabled', $disabled->payload['new_status']);
        $this->assertNotContains('apes.org.uk', SenderDomains::allowed());

        $this->actingAs($admin)->withRecentAuthentication()->post("/admin/settings/email-domains/{$domain->id}/enable");
        $this->assertNotSame(SendingDomainStatus::Disabled, $domain->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'email_sending_domain.enabled')->exists());
        $this->assertMailgunReadOnly();
    }

    public function test_an_unused_domain_can_be_removed_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $domain = EmailSendingDomain::query()->create(['domain' => 'unused.example.org']);

        $this->actingAs($admin)->withRecentAuthentication()
            ->delete("/admin/settings/email-domains/{$domain->id}")
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($domain);
        $this->assertTrue(AuditLog::query()->where('action', 'email_sending_domain.removed')->exists());
    }

    public function test_sender_must_use_a_verified_domain_and_a_valid_local_part(): void
    {
        $staff = User::factory()->staff()->create();
        $times = $this->newsletter('apes-times');
        $update = fn (array $fields) => $this->actingAs($staff)->patch("/staff/newsletters/{$times->id}", [
            'name' => $times->name,
            'sender_email' => $times->sender_email,
            'reply_to' => $times->reply_to,
            ...$fields,
        ]);

        $update(['sender_email' => 'news@apesnews.org.uk'])->assertSessionHasErrors(['sender_email' => 'The sender domain apesnews.org.uk is not verified in Mailgun.']);
        $update(['sender_email' => 'news@apes.org.uk'])->assertSessionHasErrors('sender_email');
        $update(['sender_email' => 'news@example.com'])->assertSessionHasErrors('sender_email');

        $this->markSendingDomainVerified('apesnews.org.uk');
        $update(['sender_email' => 'postmaster@apesnews.org.uk'])->assertSessionHasErrors('sender_email');
        $update(['sender_email' => 'news@apesnews.org.uk'])
            ->assertSessionHasErrors(['sender_email' => 'news@apesnews.org.uk is not an allowed From address. An admin can add it under Settings → Email sending domains.']);

        $this->domain('apesnews.org.uk')->addresses()->create(['address' => 'news@apesnews.org.uk', 'purpose' => 'news']);
        $update(['sender_email' => 'news@apesnews.org.uk'])->assertSessionHasNoErrors();
        $this->assertSame('news@apesnews.org.uk', $times->fresh()->sender_email);

        EmailSenderAddress::query()->where('address', 'myapes@apesnews.org.uk')->update(['enabled' => false]);
        $update(['sender_email' => 'myapes@apesnews.org.uk'])
            ->assertSessionHasErrors(['sender_email' => 'myapes@apesnews.org.uk has been disabled as a From address by an admin.']);
    }

    public function test_reply_to_needs_an_enabled_approved_domain(): void
    {
        $staff = User::factory()->staff()->create();
        $times = $this->newsletter('apes-times');
        $update = fn (string $replyTo) => $this->actingAs($staff)->patch("/staff/newsletters/{$times->id}", [
            'name' => $times->name,
            'sender_email' => $times->sender_email,
            'reply_to' => $replyTo,
        ]);

        $update('desk@example.com')->assertSessionHasErrors('reply_to');
        $update('desk@apes.org.uk')->assertSessionHasNoErrors();

        $this->domain('apes.org.uk')->update(['status' => SendingDomainStatus::Disabled]);
        $update('news@apes.org.uk')->assertSessionHasErrors(['reply_to' => 'The reply-to domain apes.org.uk has been disabled by an admin.']);
    }

    public function test_unchanged_addresses_still_save_when_their_domain_is_disabled(): void
    {
        $staff = User::factory()->staff()->create();
        $times = $this->newsletter('apes-times');
        $this->domain('apesnews.org.uk')->update(['status' => SendingDomainStatus::Disabled]);

        $this->actingAs($staff)->patch("/staff/newsletters/{$times->id}", [
            'name' => $times->name,
            'description' => 'Edited',
            'sender_email' => $times->sender_email,
            'reply_to' => $times->reply_to,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Edited', $times->fresh()->description);
    }

    public function test_edit_page_offers_only_verified_domains_for_sending_and_shows_send_blocks(): void
    {
        $staff = User::factory()->staff()->create();
        $times = $this->newsletter('apes-times');
        $this->markSenderVerified($times);
        $this->domain('apesnews.org.uk')->update(['status' => SendingDomainStatus::Disabled]);

        $this->actingAs($staff)->get("/staff/newsletters/{$times->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('domainOptions', [
                    ['domain' => 'apes.org.uk', 'status' => 'pending', 'can_send' => false],
                    ['domain' => 'apespetcare.org.uk', 'status' => 'pending', 'can_send' => false],
                    ['domain' => 'apesshelter.org.uk', 'status' => 'pending', 'can_send' => false],
                    ['domain' => 'myapes.me.uk', 'status' => 'pending', 'can_send' => false],
                ])
                ->where('senderOptions.0', ['address' => 'news@apespetcare.org.uk', 'label' => null, 'domain' => 'apespetcare.org.uk', 'can_send' => false])
                ->where('senderOptions', fn ($options) => collect($options)->every(fn ($option) => ! str_ends_with($option['address'], '@apesnews.org.uk')))
                ->where('newsletter.sender_verification.verified', false)
                ->where('newsletter.send_blocks.reasons', ['The sender domain apesnews.org.uk has been disabled by an admin.']));
    }

    public function test_disabling_a_domain_blocks_test_and_live_sends_and_logs_them(): void
    {
        Mail::fake();
        $this->verifyNewsletterSenders();
        $admin = User::factory()->admin()->create();
        $cic = $this->newsletter('apes-cic');
        $this->assertTrue(app(CampaignSender::class)->isVerified($cic));

        $this->domain('apesnews.org.uk')->update(['status' => SendingDomainStatus::Disabled]);
        $this->assertFalse(app(CampaignSender::class)->isVerified($cic->fresh()));
        $this->assertStringContainsString('disabled by an admin', app(CampaignSender::class)->blockedMessage($cic->fresh()));

        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::Published,
            'published_at' => now(),
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);

        try {
            app(CampaignService::class)->createTestSend($post, $admin, 'editor@example.com');
            $this->fail('Test send should be blocked.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('disabled by an admin', $exception->errors()['email'][0]);
        }

        $campaign = app(CampaignService::class)->createFromPublishedPost($post, $admin);
        $this->assertSame(CampaignStatus::Failed, $campaign->status);
        $this->assertSame(CampaignService::FAILURE_SENDER_UNVERIFIED, $campaign->failure_reason);
        $this->assertSame(0, $campaign->recipients()->count());

        $blocked = AuditLog::query()->where('action', 'newsletter.send_blocked')->orderBy('id')->get();
        $this->assertSame(['test', 'live'], $blocked->map(fn (AuditLog $log) => $log->payload['kind'])->all());
        $this->assertSame(['The sender domain apesnews.org.uk has been disabled by an admin.'], $blocked->first()->payload['reasons']);
        $this->assertSame(1, Campaign::query()->count());
        Mail::assertNothingSent();
    }

    public function test_a_disabled_reply_to_domain_also_blocks_sending(): void
    {
        $this->verifyNewsletterSenders();
        $cic = $this->newsletter('apes-cic');

        $this->domain('apes.org.uk')->update(['status' => SendingDomainStatus::Disabled]);

        $this->assertFalse(app(CampaignSender::class)->isVerified($cic));
        $this->assertSame(['The reply-to domain apes.org.uk has been disabled by an admin.'], app(CampaignSender::class)->domainProblems($cic));
    }

    public function test_daily_command_checks_enabled_domains_read_only_and_fails_for_failing_sender_domain(): void
    {
        $this->fakeMailgun(dkim: false);
        $this->fakeDmarc(true);
        $this->domain('apes.org.uk')->update(['status' => SendingDomainStatus::Disabled]);
        $this->markSendingDomainVerified('apesnews.org.uk');

        $this->artisan('mailing:check-sending-domains')->assertFailed();

        $this->assertSame(SendingDomainStatus::Failed, $this->domain('apesnews.org.uk')->status);
        $this->assertSame(SendingDomainStatus::Disabled, $this->domain('apes.org.uk')->status);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.eu.mailgun.net/v4/domains/news.myapes.me.uk');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/apes.org.uk'));
        $this->assertMailgunReadOnly();
    }

    private function failApesNewsDomain(): void
    {
        $this->domain('apesnews.org.uk')->update([
            'status' => SendingDomainStatus::Failed,
            'last_check' => ['message' => 'Failing: DKIM.'],
        ]);
    }

    public function test_admins_see_an_alert_for_a_failing_sender_domain(): void
    {
        $this->failApesNewsDomain();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('sendingDomainAlerts.0.domain', 'apesnews.org.uk')
                ->where('sendingDomainAlerts.0.message', 'Failing: DKIM.')
                ->has('sendingDomainAlerts.0.newsletters', 6));
    }

    public function test_staff_do_not_receive_sending_domain_alerts(): void
    {
        $this->failApesNewsDomain();
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/staff/newsletters')
            ->assertInertia(fn (Assert $page) => $page->missing('sendingDomainAlerts'));
    }

    public function test_a_reply_to_only_domain_failing_does_not_raise_an_alert(): void
    {
        $this->domain('apes.org.uk')->update(['status' => SendingDomainStatus::Failed]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertInertia(fn (Assert $page) => $page->where('sendingDomainAlerts', []));
    }
}
