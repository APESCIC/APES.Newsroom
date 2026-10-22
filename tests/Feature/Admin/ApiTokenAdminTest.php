<?php

namespace Tests\Feature\Admin;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Post;
use App\Models\User;
use App\Notifications\Security\ApiTokenCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApiTokenAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    public function test_admin_creates_a_token_with_expiry_and_abilities_shown_once(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->staff()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->post('/admin/api-tokens', [
                'name' => 'Zapier',
                'owner_id' => $owner->id,
                'abilities' => ['posts:read', 'posts:write'],
                'expires_in_days' => 30,
            ])
            ->assertRedirect('/admin/api-tokens');

        $token = ApiToken::query()->sole();
        $this->assertSame($owner->id, $token->user_id);
        $this->assertSame(['posts:read', 'posts:write'], $token->abilities);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
        Notification::assertSentTo($owner, ApiTokenCreatedNotification::class);

        $log = AuditLog::query()->where('action', 'api_token.issued')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('admin', $log->payload['source']);
        $this->assertSame(['posts:read', 'posts:write'], $log->payload['abilities']);

        $plain = null;
        $this->actingAs($admin)->get('/admin/api-tokens')
            ->assertInertia(function (Assert $page) use (&$plain, $token) {
                $page->where('created.id', $token->id)->where('created.token', function (string $value) use (&$plain) {
                    $plain = $value;

                    return str_starts_with($value, 'nr_admin_');
                });
            });
        $this->assertSame($token->token_hash, hash('sha256', (string) $plain));

        $this->actingAs($admin)->get('/admin/api-tokens')
            ->assertInertia(fn (Assert $page) => $page->where('created', null)->where('tokens.0.status', 'active'));
        $this->assertStringNotContainsString($token->token_hash, $this->actingAs($admin)->get('/admin/api-tokens')->getContent());
    }

    public function test_tokens_can_only_belong_to_active_staff(): void
    {
        $admin = User::factory()->admin()->create();
        $public = User::factory()->create();
        $locked = User::factory()->staff()->create(['locked_at' => now()]);

        foreach ([$public, $locked] as $owner) {
            $this->actingAs($admin)->withRecentAuthentication()
                ->post('/admin/api-tokens', ['name' => 'x', 'owner_id' => $owner->id, 'abilities' => ['posts:read'], 'expires_in_days' => 30])
                ->assertSessionHasErrors('owner_id');
        }

        $this->actingAs($admin)->withRecentAuthentication()
            ->post('/admin/api-tokens', ['name' => 'x', 'owner_id' => $admin->id, 'abilities' => ['posts:delete'], 'expires_in_days' => 7])
            ->assertSessionHasErrors(['abilities.0', 'expires_in_days']);

        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_creating_and_revoking_need_recent_authentication(): void
    {
        $admin = User::factory()->admin()->create();
        $token = ApiToken::issue(User::factory()->staff()->create(), 'ci')['model'];

        $this->actingAs($admin)->post('/admin/api-tokens', [])->assertRedirect(route('account.confirm-identity'));
        $this->actingAs($admin)->delete("/admin/api-tokens/{$token->id}", ['reason' => 'Leak'])->assertRedirect(route('account.confirm-identity'));

        $this->assertNull($token->fresh()->revoked_at);
    }

    public function test_revoked_token_stops_working_and_revocation_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $issued = ApiToken::issue(User::factory()->staff()->create(), 'ci');
        $headers = ['Authorization' => "Bearer {$issued['token']}"];

        $this->getJson('/api/admin/v1/posts', $headers)->assertOk();

        $this->actingAs($admin)->withRecentAuthentication()
            ->delete("/admin/api-tokens/{$issued['model']->id}", ['reason' => 'Leaked in a log'])
            ->assertRedirect('/admin/api-tokens');

        $this->assertNotNull($issued['model']->fresh()->revoked_at);
        $this->assertSame(['name' => 'ci', 'owner_id' => $issued['model']->user_id, 'reason' => 'Leaked in a log', 'source' => 'admin'],
            AuditLog::query()->where('action', 'api_token.revoked')->sole()->payload);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/v1/posts', $headers)->assertUnauthorized()->assertJsonPath('message', 'Admin API token has been revoked.');
    }

    public function test_expired_token_stops_working(): void
    {
        $issued = ApiToken::issue(User::factory()->staff()->create(), 'ci', null, now()->addDay());
        $headers = ['Authorization' => "Bearer {$issued['token']}"];

        $this->getJson('/api/admin/v1/posts', $headers)->assertOk();

        $this->travel(2)->days();

        $this->getJson('/api/admin/v1/posts', $headers)->assertUnauthorized()->assertJsonPath('message', 'Admin API token has expired.');
    }

    public function test_abilities_limit_what_a_token_can_do(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create(['author_id' => $admin->id]);
        $readOnly = ApiToken::issue($admin, 'reader', ['posts:read'])['token'];
        $legacy = ApiToken::issue($admin, 'legacy')['token'];

        $this->getJson('/api/admin/v1/posts', ['Authorization' => "Bearer {$readOnly}"])->assertOk();
        $this->getJson("/api/admin/v1/posts/{$post->id}", ['Authorization' => "Bearer {$readOnly}"])->assertOk();
        $this->patchJson("/api/admin/v1/posts/{$post->id}", ['title' => 'New'], ['Authorization' => "Bearer {$readOnly}"])
            ->assertForbidden()
            ->assertJsonPath('message', 'Token lacks the posts:write ability.');
        $this->postJson("/api/admin/v1/posts/{$post->id}/publish", [], ['Authorization' => "Bearer {$readOnly}"])->assertForbidden();

        $this->patchJson("/api/admin/v1/posts/{$post->id}", ['title' => 'New'], ['Authorization' => "Bearer {$legacy}"])->assertOk();
    }

    public function test_cli_can_issue_limited_expiring_tokens(): void
    {
        $staff = User::factory()->staff()->create();

        $this->artisan('newsroom:issue-admin-api-token', [
            'email' => $staff->email,
            '--name' => 'ci',
            '--ability' => ['posts:read'],
            '--expires-days' => 14,
        ])->assertSuccessful();

        $token = ApiToken::query()->sole();
        $this->assertSame(['posts:read'], $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addDays(13), now()->addDays(15)));

        $this->artisan('newsroom:issue-admin-api-token', ['email' => $staff->email, '--ability' => ['posts:delete']])->assertFailed();
        $this->artisan('newsroom:issue-admin-api-token', ['email' => $staff->email, '--expires-days' => 'soon'])->assertFailed();
        $this->assertSame(1, ApiToken::query()->count());
    }
}
