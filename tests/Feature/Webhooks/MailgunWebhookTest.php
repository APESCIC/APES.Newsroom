<?php

namespace Tests\Feature\Webhooks;

use App\Enums\CampaignRecipientStatus;
use App\Enums\ConsentAction;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ConsentEvent;
use App\Models\MailEvent;
use App\Models\MailingContact;
use App\Models\Post;
use App\Models\Suppression;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MailgunWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-webhook-signing-key-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mailgun.webhook_signing_key' => self::KEY]);
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    private function payload(array $eventData, ?string $key = null, ?int $timestamp = null, ?string $token = null): array
    {
        $timestamp ??= now()->getTimestamp();
        $token ??= Str::random(50);

        return [
            'signature' => [
                'timestamp' => (string) $timestamp,
                'token' => $token,
                'signature' => hash_hmac('sha256', $timestamp.$token, $key ?? self::KEY),
            ],
            'event-data' => [
                'id' => Str::random(22),
                'timestamp' => now()->getTimestamp(),
                'recipient' => 'reader@example.com',
                'envelope' => ['sender' => 'news@mail.apespetcare.org.uk'],
                ...$eventData,
            ],
        ];
    }

    private function deliver(array $payload): TestResponse
    {
        return $this->postJson('/webhooks/mailgun', $payload);
    }

    private function recipient(string $email = 'reader@example.com', ?string $messageId = 'msg-1@mailgun.test'): CampaignRecipient
    {
        $admin = User::factory()->admin()->create();
        $campaign = Campaign::query()->create([
            'post_id' => Post::factory()->create(['author_id' => $admin->id])->id,
            'created_by' => $admin->id,
            'lists' => ['apes_cic'],
            'snapshot' => ['title' => 'News'],
            'status' => 'completed',
        ]);

        return CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => $email,
            'status' => CampaignRecipientStatus::Accepted,
            'idempotency_key' => Str::random(20),
            'provider_message_id' => $messageId,
        ]);
    }

    public function test_bad_signatures_stale_timestamps_and_a_missing_key_are_refused(): void
    {
        $this->deliver($this->payload(['event' => 'delivered'], key: 'wrong-key'))->assertForbidden();
        $this->deliver($this->payload(['event' => 'delivered'], timestamp: now()->subMinutes(10)->getTimestamp()))->assertForbidden();
        $this->postJson('/webhooks/mailgun', ['event-data' => ['event' => 'delivered']])->assertForbidden();

        config(['services.mailgun.webhook_signing_key' => null]);
        $this->deliver($this->payload(['event' => 'delivered']))->assertStatus(503);

        $this->assertSame(0, MailEvent::query()->count());
    }

    public function test_replayed_tokens_and_duplicate_events_are_stored_once(): void
    {
        $payload = $this->payload(['event' => 'delivered', 'id' => 'event-1']);
        $this->deliver($payload)->assertOk();
        $this->deliver($payload)->assertOk()->assertSee('Already processed.');

        $again = $this->payload(['event' => 'delivered', 'id' => 'event-1']);
        $this->deliver($again)->assertOk();

        $this->assertSame(1, MailEvent::query()->where('event_id', 'mailgun:event-1')->count());
    }

    public function test_events_are_stored_with_their_details_and_linked_to_the_recipient(): void
    {
        $recipient = $this->recipient();

        $this->deliver($this->payload([
            'event' => 'failed',
            'severity' => 'permanent',
            'reason' => 'bounce',
            'delivery-status' => ['code' => 550, 'description' => 'Mailbox does not exist'],
            'message' => ['headers' => ['message-id' => 'msg-1@mailgun.test']],
        ]))->assertOk();

        $event = MailEvent::query()->sole();
        $this->assertSame('failed', $event->type);
        $this->assertSame('permanent', $event->severity);
        $this->assertSame('mail.apespetcare.org.uk', $event->domain);
        $this->assertSame($recipient->id, $event->campaign_recipient_id);
        $this->assertStringContainsString('Mailbox does not exist', $event->reason);
    }

    public function test_permanent_failures_complaints_and_unsubscribes_suppress_with_consent_history(): void
    {
        MailingContact::query()->create(['email' => 'reader@example.com']);

        $this->deliver($this->payload(['event' => 'failed', 'severity' => 'permanent']))->assertOk();
        $this->deliver($this->payload(['event' => 'complained', 'recipient' => 'grumpy@example.com']))->assertOk();
        $this->deliver($this->payload(['event' => 'unsubscribed', 'recipient' => 'leaver@example.com']))->assertOk();

        $this->assertSame(Suppression::BOUNCED, Suppression::query()->where('email', 'reader@example.com')->value('reason'));
        $this->assertSame(Suppression::COMPLAINED, Suppression::query()->where('email', 'grumpy@example.com')->value('reason'));
        $this->assertSame(Suppression::UNSUBSCRIBED_MAILGUN, Suppression::query()->where('email', 'leaver@example.com')->value('reason'));
        $this->assertSame('mailgun', Suppression::query()->where('email', 'reader@example.com')->value('source'));

        $consent = ConsentEvent::query()->where('email', 'reader@example.com')->sole();
        $this->assertSame(ConsentAction::Suppressed, $consent->action);
        $this->assertSame(Suppression::BOUNCED, $consent->evidence['reason']);
        $this->assertSame(3, AuditLog::query()->where('action', 'mailing.suppressed_by_delivery_event')->count());
    }

    public function test_the_third_temporary_failure_on_separate_sends_suppresses(): void
    {
        $fail = fn (string $message, int $daysAgo = 0) => $this->deliver($this->payload([
            'event' => 'failed',
            'severity' => 'temporary',
            'timestamp' => now()->subDays($daysAgo)->getTimestamp(),
            'message' => ['headers' => ['message-id' => $message]],
        ]))->assertOk();

        $fail('a@mailgun.test', 40);
        $fail('b@mailgun.test', 5);
        $fail('b@mailgun.test', 5);
        $this->assertFalse(Suppression::query()->where('email', 'reader@example.com')->exists(), 'Old failures and retries of one send do not count.');

        $fail('c@mailgun.test', 3);
        $this->assertFalse(Suppression::query()->exists());

        $this->deliver($this->payload(['event' => 'delivered', 'timestamp' => now()->subDays(2)->getTimestamp()]))->assertOk();
        $fail('d@mailgun.test', 1);
        $fail('e@mailgun.test');
        $this->assertFalse(Suppression::query()->exists(), 'A delivery resets the count.');

        $fail('f@mailgun.test');
        $this->assertSame(Suppression::REPEATED_SOFT_BOUNCE, Suppression::query()->where('email', 'reader@example.com')->value('reason'));
    }

    public function test_super_admins_can_change_the_temporary_failure_limit(): void
    {
        app(SettingsRepository::class)->set(SettingDefinitions::MAIL_SOFT_BOUNCE_LIMIT, 2);

        foreach (['a@mailgun.test', 'b@mailgun.test'] as $message) {
            $this->deliver($this->payload(['event' => 'failed', 'severity' => 'temporary', 'message' => ['headers' => ['message-id' => $message]]]))->assertOk();
        }

        $this->assertTrue(Suppression::query()->where('email', 'reader@example.com')->exists());
    }

    public function test_opens_and_clicks_fill_in_the_recipient_stats(): void
    {
        $recipient = $this->recipient();

        $this->deliver($this->payload(['event' => 'clicked', 'url' => 'https://www.apesnews.org.uk/articles/news', 'user-variables' => ['campaign_recipient_id' => $recipient->id]]))->assertOk();

        $recipient->refresh();
        $this->assertNotNull($recipient->clicked_at);
        $this->assertNotNull($recipient->opened_at);
        $this->assertSame('https://www.apesnews.org.uk/articles/news', MailEvent::query()->sole()->url);
    }

    public function test_mail_events_older_than_24_months_are_pruned(): void
    {
        MailEvent::query()->create(['event_id' => 'old', 'type' => 'delivered', 'recipient' => 'a@example.com', 'occurred_at' => now()->subMonths(25)]);
        MailEvent::query()->create(['event_id' => 'new', 'type' => 'delivered', 'recipient' => 'a@example.com', 'occurred_at' => now()->subMonths(23)]);

        $this->artisan('newsroom:purge-security-data')->expectsOutputToContain('Purged 1 mail event(s).')->assertSuccessful();

        $this->assertSame(['new'], MailEvent::query()->pluck('event_id')->all());
    }

    public function test_the_daily_sync_imports_mailgun_suppression_lists_once(): void
    {
        config(['services.mailgun.secret' => 'key-1234', 'services.mailgun.domain' => null]);
        Http::fake([
            'api.eu.mailgun.net/v3/apespetcare.org.uk/bounces*' => Http::response(['items' => [
                ['address' => 'Bounced@Example.com', 'code' => '550', 'error' => 'No such user', 'created_at' => 'Fri, 23 Oct 2026 10:00:00 UTC'],
            ], 'paging' => ['next' => 'https://api.eu.mailgun.net/v3/apespetcare.org.uk/bounces?page=next&limit=1000']], 200),
            'api.eu.mailgun.net/v3/apespetcare.org.uk/complaints*' => Http::response(['items' => [['address' => 'spam@example.com']], 'paging' => []]),
            'api.eu.mailgun.net/v3/*' => Http::response(['items' => [], 'paging' => []]),
        ]);

        $this->artisan('mailing:sync-mailgun-suppressions', ['--domain' => 'apespetcare.org.uk'])->assertSuccessful();
        $this->artisan('mailing:sync-mailgun-suppressions', ['--domain' => 'apespetcare.org.uk'])->assertSuccessful();

        $this->assertSame(Suppression::BOUNCED, Suppression::query()->where('email', 'bounced@example.com')->value('reason'));
        $this->assertSame(Suppression::COMPLAINED, Suppression::query()->where('email', 'spam@example.com')->value('reason'));
        $this->assertSame(1, MailEvent::query()->where('recipient', 'bounced@example.com')->count());
    }
}
