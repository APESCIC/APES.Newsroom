<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SsoOnlySignInTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function ssoOnlyAccounts(): array
    {
        return [
            'cloudron staff' => ['cloudron_staff'],
            'cloudron admin' => ['cloudron_admin'],
            'password account with admin role' => ['password_admin'],
        ];
    }

    #[DataProvider('ssoOnlyAccounts')]
    public function test_magic_link_request_is_silently_refused(string $account): void
    {
        Notification::fake();
        Log::spy();
        $user = $this->makeAccount($account);

        $staffResponse = $this->post('/login/magic-link', ['email' => $user->email]);
        $unknownResponse = $this->post('/login/magic-link', ['email' => 'nobody@example.com']);

        $staffResponse->assertOk();
        $this->assertSame($unknownResponse->getContent(), $staffResponse->getContent());
        $this->assertSame(0, MagicLinkToken::count());
        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->with('auth.sso_required_refused', Mockery::on(
            fn (array $context) => $context['flow'] === 'magic_link_request' && $context['user_id'] === $user->id
        ));
    }

    #[DataProvider('ssoOnlyAccounts')]
    public function test_an_existing_magic_link_cannot_be_used(string $account): void
    {
        $user = $this->makeAccount($account);
        $rawToken = str()->random(64);
        $user->magicLinkTokens()->create([
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->get(URL::temporarySignedRoute('magic-link.consume', now()->addMinutes(15), ['token' => $rawToken]));

        $response->assertForbidden();
        $this->assertGuest();
    }

    #[DataProvider('ssoOnlyAccounts')]
    public function test_password_reset_link_is_silently_refused(string $account): void
    {
        Notification::fake();
        $user = $this->makeAccount($account);

        $staffResponse = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $unknownResponse = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com']);

        $message = __('A reset link will be emailed if the account exists.');
        $staffResponse->assertRedirect('/forgot-password')->assertSessionHas('status', $message);
        $unknownResponse->assertRedirect('/forgot-password')->assertSessionHas('status', $message);
        Notification::assertNothingSent();
    }

    #[DataProvider('ssoOnlyAccounts')]
    public function test_password_reset_cannot_be_completed(string $account): void
    {
        $user = $this->makeAccount($account);
        $token = Password::broker()->createToken($user);

        $response = $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ]);

        $response->assertSessionHasErrors(['email' => __(Password::INVALID_TOKEN)]);
        $this->assertFalse(Hash::check('a-brand-new-password', (string) $user->fresh()->password));
    }

    #[DataProvider('ssoOnlyAccounts')]
    public function test_password_login_is_refused_with_the_generic_failure(string $account): void
    {
        $user = $this->makeAccount($account);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    }

    public function test_public_member_flows_are_unchanged(): void
    {
        Notification::fake();
        $member = User::factory()->create(['password' => Hash::make('password')]);

        $this->post('/login/magic-link', ['email' => $member->email])->assertOk();
        Notification::assertSentTo($member, MagicLinkNotification::class);

        $this->post('/forgot-password', ['email' => $member->email]);
        Notification::assertSentTo($member, ResetPassword::class);

        $this->post('/login', ['email' => $member->email, 'password' => 'password'])
            ->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($member);
    }

    private function makeAccount(string $account): User
    {
        return match ($account) {
            'cloudron_staff' => User::factory()->staff()->create(),
            'cloudron_admin' => User::factory()->admin()->create(),
            'password_admin' => tap(User::factory()->create(['password' => Hash::make('password')]), function (User $user) {
                $user->forceFill(['role' => Role::Admin])->save();
            }),
        };
    }
}
