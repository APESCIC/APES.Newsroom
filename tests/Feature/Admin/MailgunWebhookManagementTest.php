<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\EmailSendingDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MailgunWebhookManagementTest extends TestCase
{
    use RefreshDatabase;

    private EmailSendingDomain $domain;

    private string $callback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config([
            'services.mailgun.secret' => 'sending-key-1234',
            'services.mailgun.management_key' => 'management-key-5678',
            'services.mailgun.domain' => null,
        ]);
        $this->domain = EmailSendingDomain::query()->where('domain', 'apespetcare.org.uk')->firstOrFail();
        $this->callback = route('webhooks.mailgun');
    }

    /**
     * @param  array<string, list<string>>  $webhooks
     */
    private function fakeMailgun(array $webhooks): void
    {
        Http::fake([
            'api.eu.mailgun.net/v3/domains/mail.apespetcare.org.uk/webhooks' => function (Request $request) use (&$webhooks) {
                if ($request->method() === 'POST') {
                    $webhooks[$request['id']] = [$request['url']];
                }

                return Http::response(['webhooks' => collect($webhooks)->map(fn (array $urls) => ['urls' => $urls])->all()]);
            },
            'api.eu.mailgun.net/v3/domains/mail.apespetcare.org.uk/webhooks/*' => function (Request $request) use (&$webhooks) {
                $event = basename(parse_url($request->url(), PHP_URL_PATH));
                if ($request->method() === 'DELETE') {
                    unset($webhooks[$event]);
                } else {
                    parse_str(str_replace('url=', 'url[]=', $request->body()), $form);
                    $webhooks[$event] = $form['url'];
                }

                return Http::response(['message' => 'ok']);
            },
            'api.eu.mailgun.net/v3/domains/mail.apespetcare.org.uk/tracking*' => Http::response(['tracking' => ['open' => ['active' => true], 'click' => ['active' => 'htmlonly']]]),
        ]);
    }

    public function test_register_adds_the_newsroom_url_once_and_keeps_other_urls(): void
    {
        $this->fakeMailgun(['delivered' => ['https://other.example.com/hook']]);
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->withRecentAuthentication()
            ->post("/admin/settings/email-domains/{$this->domain->id}/webhooks/register")
            ->assertSessionHasNoErrors();

        $status = $this->domain->fresh()->webhooks;
        $this->assertTrue(collect($status['events'])->every(fn (array $event) => $event['registered']));
        $this->assertSame(['https://other.example.com/hook', $this->callback], $status['events']['delivered']['urls']);
        $this->assertTrue($status['tracking']['open']);
        $this->assertTrue($status['tracking']['click']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Basic '.base64_encode('api:management-key-5678')));

        Http::fake();
        $this->fakeMailgun(collect($status['events'])->map(fn (array $event) => $event['urls'])->all());
        $this->actingAs($owner)->withRecentAuthentication()
            ->post("/admin/settings/email-domains/{$this->domain->id}/webhooks/register")
            ->assertSessionHas('status', 'Webhooks on apespetcare.org.uk were already up to date.');
        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PUT', 'DELETE'], true));

        $this->assertSame(2, AuditLog::query()->where('action', 'email_sending_domain.webhooks_register')->count());
    }

    public function test_repair_drops_stale_newsroom_urls_and_remove_keeps_others(): void
    {
        $this->fakeMailgun([
            'delivered' => ['https://old-beta.example.org/webhooks/mailgun', 'https://other.example.com/hook'],
            'clicked' => [$this->callback],
        ]);
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->withRecentAuthentication()->post("/admin/settings/email-domains/{$this->domain->id}/webhooks/repair")->assertSessionHasNoErrors();
        $this->assertSame(['https://other.example.com/hook', $this->callback], $this->domain->fresh()->webhooks['events']['delivered']['urls']);

        $this->actingAs($owner)->withRecentAuthentication()->delete("/admin/settings/email-domains/{$this->domain->id}/webhooks")->assertSessionHasNoErrors();
        $events = $this->domain->fresh()->webhooks['events'];
        $this->assertSame(['https://other.example.com/hook'], $events['delivered']['urls']);
        $this->assertSame([], $events['clicked']['urls']);
        $this->assertTrue(AuditLog::query()->where('action', 'email_sending_domain.webhooks_remove')->exists());
    }

    public function test_tracking_changes_are_applied_in_mailgun_and_audited(): void
    {
        $this->fakeMailgun([]);
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->withRecentAuthentication()
            ->put("/admin/settings/email-domains/{$this->domain->id}/tracking", ['track_opens' => false, 'track_clicks' => true])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->domain->fresh()->track_opens);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/tracking/open')
            && $request['active'] === 'no');
        $audit = AuditLog::query()->where('action', 'email_sending_domain.tracking_changed')->sole();
        $this->assertSame(['track_opens' => false, 'track_clicks' => true], $audit->payload['to']);
    }

    public function test_admins_can_only_check_and_mailgun_errors_are_shown(): void
    {
        Http::fake(['api.eu.mailgun.net/*' => Http::response(['message' => 'Invalid private key'], 401)]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()->post("/admin/settings/email-domains/{$this->domain->id}/webhooks/register")->assertForbidden();
        $this->actingAs($admin)->withRecentAuthentication()->put("/admin/settings/email-domains/{$this->domain->id}/tracking", ['track_opens' => true, 'track_clicks' => true])->assertForbidden();

        $this->actingAs($admin)->withRecentAuthentication()->post("/admin/settings/email-domains/{$this->domain->id}/webhooks/refresh");
        $this->assertStringContainsString('Invalid private key', $this->domain->fresh()->webhooks['error']);
    }

    public function test_the_page_warns_when_the_management_key_is_the_sending_key(): void
    {
        config(['services.mailgun.management_key' => 'sending-key-1234']);
        $owner = User::factory()->superAdmin()->create();

        $this->actingAs($owner)->get('/admin/settings/email-domains')
            ->assertInertia(fn (Assert $page) => $page
                ->where('webhookManagement.uses_sending_key', true)
                ->where('webhookManagement.can_manage', true)
                ->where('webhookManagement.callback_url', $this->callback));
    }
}
