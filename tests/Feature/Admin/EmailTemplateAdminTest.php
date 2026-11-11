<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Services\Auth\RecentAuthentication;
use App\Services\EmailTemplates\TemplatedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function admin_can_view_index_and_edit(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/settings/email-templates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/EmailTemplates/Index')
                ->has('groups.account')
                ->has('groups.security')
                ->has('groupLabels'));

        $this->actingAs($admin)
            ->get('/admin/settings/email-templates/magic_link')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/EmailTemplates/Edit')
                ->where('definition.key', 'magic_link')
                ->where('is_overridden', false)
                ->has('versions')
                ->has('testRecipient'));
    }

    #[Test]
    public function staff_cannot_access_email_templates(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/admin/settings/email-templates')->assertForbidden();
        $this->actingAs($staff)->get('/admin/settings/email-templates/magic_link')->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()
            ->put('/admin/settings/email-templates/magic_link', [
                'subject' => 'x',
                'body' => 'Click {{url}}',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function save_is_blocked_when_a_required_placeholder_is_missing(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->from('/admin/settings/email-templates/magic_link')
            ->put('/admin/settings/email-templates/magic_link', [
                'subject' => 'Sign in',
                'body' => 'No link placeholder here.',
            ])
            ->assertRedirect('/admin/settings/email-templates/magic_link')
            ->assertSessionHasErrors('template');

        $this->assertDatabaseCount('email_templates', 0);
    }

    #[Test]
    public function saving_an_override_is_used_when_mail_is_built(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->put('/admin/settings/email-templates/magic_link', [
                'subject' => 'Custom magic subject',
                'body' => "Use this override link.\n\n{{url}}",
            ])
            ->assertRedirect('/admin/settings/email-templates/magic_link')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('email_templates', [
            'key' => 'magic_link',
            'subject' => 'Custom magic subject',
        ]);
        $this->assertDatabaseCount('email_template_versions', 1);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'email_template.updated',
        ]);

        $message = app(TemplatedMail::class)->build(
            key: 'magic_link',
            placeholders: ['url' => 'https://example.test/magic'],
            actionLabel: 'Sign in',
            actionUrl: 'https://example.test/magic',
            fallback: fn () => throw new \RuntimeException('Fallback should not run when an override exists.'),
        );

        $this->assertSame('Custom magic subject', $message->subject);

        Notification::fake();
        Notification::route('mail', 'reader@example.test')
            ->notify(new MagicLinkNotification('https://example.test/magic'));
        Notification::assertSentOnDemand(MagicLinkNotification::class);
    }

    #[Test]
    public function revert_restores_default_behaviour(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->put('/admin/settings/email-templates/magic_link', [
                'subject' => 'Override subject',
                'body' => 'Override body {{url}}',
            ])
            ->assertRedirect();

        $this->assertTrue(app(TemplatedMail::class)->hasOverride('magic_link'));

        $this->actingAs($admin)->withRecentAuthentication()
            ->post('/admin/settings/email-templates/magic_link/revert')
            ->assertRedirect('/admin/settings/email-templates/magic_link')
            ->assertSessionHas('status');

        $this->assertFalse(app(TemplatedMail::class)->hasOverride('magic_link'));
        $this->assertDatabaseCount('email_templates', 0);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'email_template.reverted',
        ]);
    }

    #[Test]
    public function preview_returns_html_and_text(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->postJson('/admin/settings/email-templates/magic_link/preview', [
                'subject' => 'Preview subject',
                'body' => 'Body with **bold** and {{url}}',
            ])
            ->assertOk()
            ->assertJsonStructure(['subject', 'html', 'text']);

        $html = $response->json('html');
        $text = $response->json('text');
        $this->assertIsString($html);
        $this->assertIsString($text);
        $this->assertNotSame('', $html);
        $this->assertStringContainsString('https://example.test/magic', $html);
        $this->assertNotSame('', $text);
    }

    #[Test]
    public function test_send_reaches_the_array_mailer_and_is_throttled(): void
    {
        // Mail::fake() drops Mail::html messages, so assert on the array transport instead.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson('/admin/settings/email-templates/magic_link/test', [
                'subject' => 'Test subject',
                'body' => 'Test body {{url}}',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $this->assertSame(1, $transport->messages()->count());
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'email_template.test_sent',
        ]);

        config(['mailing.test_send.per_user_per_minute' => 1]);
        RateLimiter::clear('test-send:user:'.$admin->getKey());
        RateLimiter::clear('test-send:target:email-template:magic_link');

        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson('/admin/settings/email-templates/magic_link/test')
            ->assertOk();

        $this->actingAs($admin)->withRecentAuthentication()
            ->postJson('/admin/settings/email-templates/magic_link/test')
            ->assertStatus(429)
            ->assertJsonStructure(['message']);
    }

    #[Test]
    public function security_template_save_without_recent_auth_returns_423(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->putJson('/admin/settings/email-templates/password_changed', [
                'subject' => 'Password changed',
                'body' => 'Your password was changed.',
            ])
            ->assertStatus(423);

        // Within default reauth window but outside the 300s security window.
        $this->actingAs($admin)
            ->withSession([RecentAuthentication::SESSION_KEY => now()->subSeconds(600)->getTimestamp()])
            ->putJson('/admin/settings/email-templates/password_changed', [
                'subject' => 'Password changed',
                'body' => 'Your password was changed.',
            ])
            ->assertStatus(423);

        $this->assertDatabaseCount('email_templates', 0);
    }

    #[Test]
    public function restore_creates_a_new_version_from_history(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->put('/admin/settings/email-templates/magic_link', [
                'subject' => 'First subject',
                'body' => 'First body {{url}}',
            ])
            ->assertRedirect();

        $this->actingAs($admin)->withRecentAuthentication()
            ->put('/admin/settings/email-templates/magic_link', [
                'subject' => 'Second subject',
                'body' => 'Second body {{url}}',
            ])
            ->assertRedirect();

        $firstVersionId = EmailTemplate::query()->where('key', 'magic_link')->firstOrFail()
            ->versions()->reorder()->orderBy('id')->value('id');

        $this->actingAs($admin)->withRecentAuthentication()
            ->post("/admin/settings/email-templates/magic_link/versions/{$firstVersionId}/restore")
            ->assertRedirect('/admin/settings/email-templates/magic_link')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('email_templates', [
            'key' => 'magic_link',
            'subject' => 'First subject',
            'body' => 'First body {{url}}',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'email_template.restored',
        ]);
        $this->assertSame(3, EmailTemplate::query()->where('key', 'magic_link')->firstOrFail()->versions()->count());
        $this->assertInstanceOf(AuditLog::class, AuditLog::query()->where('action', 'email_template.restored')->first());
    }
}
