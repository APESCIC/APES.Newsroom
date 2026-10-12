<?php

namespace Tests\Feature\Account;

use App\Http\Middleware\EnsureAuthEpoch;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_password_user_can_change_their_password(): void
    {
        $user = User::factory()->create();
        $epoch = $user->auth_epoch;

        $this->actingAs($user)
            ->from(route('account.security'))
            ->put(route('account.password.update'), [
                'current_password' => 'password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'password-updated')
            ->assertSessionHas(EnsureAuthEpoch::SESSION_KEY, $epoch + 1);

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertSame($epoch + 1, $user->auth_epoch);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.password_changed', 'actor_id' => $user->id]);
    }

    public function test_the_current_session_survives_and_other_sessions_end(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $this->get(route('account.show'))->assertOk();

        // A session stamped with the previous epoch is now rejected.
        $this->withSession([EnsureAuthEpoch::SESSION_KEY => 0])
            ->get(route('account.show'))
            ->assertRedirect(route('login'));
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'not-my-password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertSame(0, AuditLog::query()->where('action', 'account.password_changed')->count());
    }

    public function test_sso_users_are_refused(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->put(route('account.password.update'), [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertForbidden();

        $this->assertNull($staff->fresh()->password);
    }

    public function test_magic_link_user_must_reauthenticate_before_setting_a_first_password(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)
            ->from(route('account.security'))
            ->put(route('account.password.update'), [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('account.confirm-identity'));

        $this->assertNull($user->fresh()->password);

        $this->actingAs($user)
            ->withRecentAuthentication()
            ->put(route('account.password.update'), [
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHas('status', 'password-updated');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_password_change_is_rate_limited(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->actingAs($user)->put(route('account.password.update'), [
                'current_password' => 'wrong-guess-'.$attempt,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);
        }

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_security_page_reflects_the_account_type(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Security')
                ->where('sso_managed', false)
                ->where('has_password', true));

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page->where('sso_managed', true));
    }
}
