<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_passwords_under_twelve_characters(): void
    {
        $this->post('/register', [
            'name' => 'Short',
            'email' => 'short@example.com',
            'password' => 'elevenchars',
            'password_confirmation' => 'elevenchars',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_reset_rejects_passwords_under_twelve_characters(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'elevenchars',
                'password_confirmation' => 'elevenchars',
            ])->assertSessionHasErrors('password');

            return true;
        });
    }

    public function test_password_change_rejects_passwords_under_twelve_characters(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => 'elevenchars',
            'password_confirmation' => 'elevenchars',
        ])->assertSessionHasErrors('password');
    }

    public function test_overlong_passwords_are_rejected(): void
    {
        $this->assertTrue(Validator::make(['password' => str_repeat('a', 129)], ['password' => PasswordPolicy::rule()])->fails());
        $this->assertFalse(Validator::make(['password' => str_repeat('a', 128)], ['password' => PasswordPolicy::rule()])->fails());
    }

    public function test_breached_passwords_are_rejected_when_the_check_is_enabled(): void
    {
        config(['security.password.check_breached' => true]);
        // SHA-1 of "correct-horse-battery-staple" split into prefix / suffix.
        $sha1 = strtoupper(sha1('correct-horse-battery-staple'));
        Http::fake([
            'api.pwnedpasswords.com/range/'.substr($sha1, 0, 5) => Http::response(substr($sha1, 5).':42'),
        ]);

        $this->assertTrue(Validator::make(['password' => 'correct-horse-battery-staple'], ['password' => PasswordPolicy::rule()])->fails());
    }

    public function test_breached_check_fails_open_when_the_service_is_unreachable(): void
    {
        config(['security.password.check_breached' => true]);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 503)]);

        $this->assertFalse(Validator::make(['password' => 'correct-horse-battery-staple'], ['password' => PasswordPolicy::rule()])->fails());
    }
}
