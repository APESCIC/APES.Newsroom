<?php

namespace Tests\Feature\Account;

use App\Http\Controllers\Auth\CloudronOidcController;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Notifications\ReauthenticationCodeNotification;
use App\Services\Auth\CloudronOidcProvider;
use App\Services\Auth\RecentAuthentication;
use App\Services\Auth\StaffOidcIdentity;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array<string, array{0: Closure(): User, 1: Closure(TestCase, User): TestResponse}>
     */
    public static function protectedActions(): array
    {
        return [
            'account deletion' => [
                fn () => User::factory()->create(),
                fn (TestCase $test, User $user) => $test->from(route('account.show'))->delete(route('account.destroy')),
            ],
            'email change' => [
                fn () => User::factory()->create(),
                fn (TestCase $test, User $user) => $test->from(route('account.show'))
                    ->patch(route('account.update'), ['name' => $user->name, 'email' => 'new@example.com']),
            ],
            'webhook create' => [
                fn () => User::factory()->staff()->create(),
                fn (TestCase $test, User $user) => $test->from(route('staff.webhooks.index'))->post(route('staff.webhooks.store'), [
                    'name' => 'Partner', 'url' => 'https://hooks.example.com', 'events' => ['post.published'],
                ]),
            ],
            'webhook rotate' => [
                fn () => User::factory()->staff()->create(),
                fn (TestCase $test, User $user) => $test->from(route('staff.webhooks.index'))->patch(
                    route('staff.webhooks.update', self::webhook()),
                    ['name' => 'Partner', 'url' => 'https://hooks.example.com', 'events' => ['post.published'], 'is_active' => true, 'rotate_secret' => true],
                ),
            ],
            'webhook delete' => [
                fn () => User::factory()->staff()->create(),
                fn (TestCase $test, User $user) => $test->from(route('staff.webhooks.index'))->delete(route('staff.webhooks.destroy', self::webhook())),
            ],
            'settings update' => [
                fn () => User::factory()->admin()->create(),
                fn (TestCase $test, User $user) => $test->from('/admin/settings')
                    ->put('/admin/settings', ['settings' => ['editor' => ['driver' => 'tinymce']]]),
            ],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     * @param  Closure(TestCase, User): TestResponse  $act
     */
    #[DataProvider('protectedActions')]
    public function test_sensitive_actions_are_refused_without_recent_authentication(Closure $makeUser, Closure $act): void
    {
        $user = $makeUser();
        $this->actingAs($user);

        $response = $act($this, $user);

        $response->assertRedirect(route('account.confirm-identity'));
        $this->assertNotNull(session('url.intended'));
        $this->assertModelExists($user);
        $this->assertNotSame('new@example.com', $user->fresh()->email);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.updated']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'webhook.created']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'webhook.secret_rotated']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'webhook.deleted']);
    }

    /**
     * @param  Closure(): User  $makeUser
     * @param  Closure(TestCase, User): TestResponse  $act
     */
    #[DataProvider('protectedActions')]
    public function test_sensitive_actions_proceed_after_recent_authentication(Closure $makeUser, Closure $act): void
    {
        $user = $makeUser();
        $this->actingAs($user)->withRecentAuthentication();

        $response = $act($this, $user);

        $this->assertNotSame(route('account.confirm-identity'), $response->headers->get('Location'));
    }

    public function test_confirmation_expires_after_the_timeout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withRecentAuthentication();
        Date::setTestNow(now()->addSeconds((int) config('security.reauth_timeout') + 1));

        $this->delete(route('account.destroy'))->assertRedirect(route('account.confirm-identity'));
        $this->assertModelExists($user);
    }

    public function test_name_only_profile_update_needs_no_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('account.update'), ['name' => 'Renamed', 'email' => $user->email])
            ->assertSessionHas('status', 'profile-updated');
    }

    public function test_interactive_sign_in_counts_as_recent_authentication(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNotNull(session(RecentAuthentication::SESSION_KEY));
    }

    public function test_password_user_confirms_with_their_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.confirm-identity'))
            ->assertInertia(fn (Assert $page) => $page->component('Account/ConfirmIdentity')->where('method', 'password'));

        $this->post(route('account.confirm-identity.password'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->assertNull(session(RecentAuthentication::SESSION_KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.reauthentication_failed']);

        $this->withSession(['url.intended' => route('account.security')])
            ->post(route('account.confirm-identity.password'), ['password' => 'password'])
            ->assertRedirect(route('account.security'));
        $this->assertNotNull(session(RecentAuthentication::SESSION_KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.reauthenticated', 'actor_id' => $user->id]);
    }

    public function test_magic_link_user_confirms_with_an_emailed_code(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)
            ->get(route('account.confirm-identity'))
            ->assertInertia(fn (Assert $page) => $page->where('method', 'email_code'));

        $this->post(route('account.confirm-identity.password'), ['password' => 'anything'])->assertForbidden();

        $this->post(route('account.confirm-identity.code.send'))->assertSessionHas('status', 'code-sent');

        $code = null;
        Notification::assertSentTo($user, ReauthenticationCodeNotification::class, function (ReauthenticationCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post(route('account.confirm-identity.code'), ['code' => $wrong])->assertSessionHasErrors('code');

        $this->post(route('account.confirm-identity.code'), ['code' => $code])->assertRedirect(route('account.show'));
        $this->assertNotNull(session(RecentAuthentication::SESSION_KEY));

        // Codes are single use.
        $this->post(route('account.confirm-identity.code'), ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_emailed_code_expires(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)->post(route('account.confirm-identity.code.send'));

        $code = null;
        Notification::assertSentTo($user, ReauthenticationCodeNotification::class, function (ReauthenticationCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        Date::setTestNow(now()->addMinutes(11));

        $this->post(route('account.confirm-identity.code'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull(session(RecentAuthentication::SESSION_KEY));
    }

    public function test_emailed_code_is_discarded_after_five_wrong_attempts(): void
    {
        Notification::fake();
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)->post(route('account.confirm-identity.code.send'));

        $code = null;
        Notification::assertSentTo($user, ReauthenticationCodeNotification::class, function (ReauthenticationCodeNotification $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        $wrong = $code === '000000' ? '111111' : '000000';
        foreach (range(1, 4) as $attempt) {
            $this->post(route('account.confirm-identity.code'), ['code' => $wrong]);
        }

        // The rate limiter allows five a minute; move on so only the attempt cap applies.
        Date::setTestNow(now()->addMinute());
        $this->post(route('account.confirm-identity.code'), ['code' => $wrong]);

        Date::setTestNow(now()->addMinute());
        $this->post(route('account.confirm-identity.code'), ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_sso_user_reauthenticates_through_cloudron_with_max_age(): void
    {
        config(['services.cloudron_oidc.discovery_url' => 'https://my.example.com/.well-known/openid-configuration']);
        $staff = User::factory()->staff()->create(['external_id' => 'oidc-staff']);

        $provider = Mockery::mock(CloudronOidcProvider::class);
        $provider->shouldReceive('authorizationUrl')
            ->with(Mockery::type('string'), ['max_age' => 0, 'prompt' => 'login'])
            ->andReturn('https://my.example.com/auth');
        $provider->shouldReceive('getState')->andReturn('reauth-state');
        $provider->shouldReceive('getPkceCode')->andReturn('reauth-pkce');
        $provider->shouldReceive('exchangeCodeForIdentity')->andReturn(new StaffOidcIdentity(
            sub: 'oidc-staff', email: $staff->email, name: $staff->name, emailVerified: true, authTime: now()->getTimestamp(),
        ));
        $this->app->instance(CloudronOidcProvider::class, $provider);

        $this->actingAs($staff)
            ->get(route('account.confirm-identity'))
            ->assertInertia(fn (Assert $page) => $page->where('method', 'sso'));

        $this->get(route('account.confirm-identity.sso'))->assertRedirect('https://my.example.com/auth');

        $this->withSession(['url.intended' => route('staff.webhooks.index')])
            ->get('/auth/cloudron/callback?code=c&state=reauth-state')
            ->assertRedirect(route('staff.webhooks.index'));

        $this->assertNotNull(session(RecentAuthentication::SESSION_KEY));
        $this->assertAuthenticatedAs($staff);
    }

    public function test_sso_reauthentication_rejects_a_stale_auth_time(): void
    {
        $staff = $this->staffMidReauth(authTime: now()->subHour()->getTimestamp(), sub: 'oidc-staff');

        $this->get('/auth/cloudron/callback?code=c&state=reauth-state')
            ->assertRedirect(route('account.confirm-identity'))
            ->assertSessionHasErrors('sso');

        $this->assertNull(session(RecentAuthentication::SESSION_KEY));
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.reauthentication_failed', 'actor_id' => $staff->id]);
    }

    public function test_sso_reauthentication_rejects_a_different_directory_account(): void
    {
        $this->staffMidReauth(authTime: now()->getTimestamp(), sub: 'someone-else');

        $this->get('/auth/cloudron/callback?code=c&state=reauth-state')
            ->assertSessionHasErrors('sso');

        $this->assertNull(session(RecentAuthentication::SESSION_KEY));
    }

    public function test_signed_in_user_hitting_the_callback_without_reauth_goes_home(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/auth/cloudron/callback?code=c&state=x')
            ->assertRedirect(route('home'));
    }

    private function staffMidReauth(int $authTime, string $sub): User
    {
        $staff = User::factory()->staff()->create(['external_id' => 'oidc-staff']);

        $provider = Mockery::mock(CloudronOidcProvider::class);
        $provider->shouldReceive('exchangeCodeForIdentity')->andReturn(new StaffOidcIdentity(
            sub: $sub, email: $staff->email, name: $staff->name, emailVerified: true, authTime: $authTime,
        ));
        $this->app->instance(CloudronOidcProvider::class, $provider);

        $this->actingAs($staff)->withSession([
            CloudronOidcController::REAUTH_SESSION_KEY => ['user_id' => $staff->id, 'started_at' => now()->getTimestamp()],
            'cloudron_oidc_state' => 'reauth-state',
            'cloudron_oidc_nonce' => 'n',
            'cloudron_oidc_pkce' => 'p',
        ]);

        return $staff;
    }

    private static function webhook(): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create([
            'name' => 'Partner',
            'url' => 'https://hooks.example.com',
            'secret' => WebhookEndpoint::generateSecret(),
            'events' => ['post.published'],
            'is_active' => true,
        ]);
    }
}
