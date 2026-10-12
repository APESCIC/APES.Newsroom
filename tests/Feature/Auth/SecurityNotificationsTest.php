<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Models\UserSession;
use App\Notifications\Security\ApiTokenCreatedNotification;
use App\Notifications\Security\EmailChangeRequestedNotification;
use App\Notifications\Security\NewDeviceSignInNotification;
use App\Notifications\Security\PasskeyAddedNotification;
use App\Notifications\Security\PasskeyRemovedNotification;
use App\Notifications\Security\PasswordChangedNotification;
use App\Notifications\Security\RecoveryCodesRegeneratedNotification;
use App\Notifications\Security\SecurityNotification;
use App\Notifications\Security\TwoFactorDisabledNotification;
use App\Notifications\Security\TwoFactorEnabledNotification;
use App\Services\Auth\TwoFactor\TwoFactorService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class SecurityNotificationsTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const NEW_PASSWORD = 'correct-horse-battery-staple';

    private const CHROME_ON_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

    private const FIREFOX_ON_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_changing_the_password_notifies_the_account_holder(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    public function test_resetting_the_password_notifies_the_account_holder(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        Notification::assertSentTo(
            $user,
            PasswordChangedNotification::class,
            fn (PasswordChangedNotification $notification) => str_contains(implode(' ', $notification->toMail($user)->introLines), 'reset'),
        );
    }

    public function test_requesting_an_email_change_notifies_the_current_address(): void
    {
        $user = User::factory()->create(['email' => 'current@example.com']);

        $this->actingAs($user)->withRecentAuthentication()->patch(route('account.update'), [
            'name' => $user->name,
            'email' => 'next@example.com',
        ]);

        Notification::assertSentTo($user, EmailChangeRequestedNotification::class, function (EmailChangeRequestedNotification $notification, array $channels, User $notifiable) {
            return $notifiable->email === 'current@example.com'
                && str_contains($notification->toMail($notifiable)->actionUrl, '/account/email-change/');
        });
    }

    public function test_signing_in_from_an_unfamiliar_device_notifies_the_account_holder(): void
    {
        $user = $this->userWithDeviceHistory(lastSeen: now()->subDays(3));

        $this->signIn($user, self::FIREFOX_ON_LINUX);

        Notification::assertSentTo(
            $user,
            NewDeviceSignInNotification::class,
            fn (NewDeviceSignInNotification $notification) => $notification->device === 'Firefox on GNU/Linux',
        );
    }

    public function test_signing_in_from_a_familiar_device_sends_nothing(): void
    {
        $user = $this->userWithDeviceHistory(lastSeen: now()->subDays(3));

        $this->signIn($user, self::CHROME_ON_WINDOWS);

        Notification::assertNotSentTo($user, NewDeviceSignInNotification::class);
    }

    public function test_a_device_not_seen_for_ninety_days_counts_as_unfamiliar(): void
    {
        $user = $this->userWithDeviceHistory(lastSeen: now()->subDays(91));

        $this->signIn($user, self::CHROME_ON_WINDOWS);

        Notification::assertSentTo($user, NewDeviceSignInNotification::class);
    }

    public function test_the_first_tracked_sign_in_sends_nothing(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->signIn($user, self::FIREFOX_ON_LINUX);

        Notification::assertNotSentTo($user, NewDeviceSignInNotification::class);
    }

    public function test_issuing_an_api_token_notifies_its_owner(): void
    {
        $staff = User::factory()->staff()->create();

        $this->artisan('newsroom:issue-admin-api-token', ['email' => $staff->email, '--name' => 'publishing-bot'])->assertSuccessful();

        Notification::assertSentTo(
            $staff,
            ApiTokenCreatedNotification::class,
            fn (ApiTokenCreatedNotification $notification) => $notification->tokenName === 'publishing-bot',
        );
    }

    public function test_turning_on_two_factor_notifies_the_account_holder(): void
    {
        $user = User::factory()->create();
        app(TwoFactorService::class)->beginSetup($user);

        $this->actingAs($user)
            ->withSession($this->recentlyConfirmed())
            ->post(route('account.two-factor.confirm'), ['code' => $this->totpFor($user)]);

        Notification::assertSentTo($user, TwoFactorEnabledNotification::class);
    }

    /**
     * @return array<string, array{0: SecurityNotification}>
     */
    public static function notifications(): array
    {
        return [
            'password changed' => [new PasswordChangedNotification],
            'email change requested' => [new EmailChangeRequestedNotification('n***@example.com', 'https://example.test/cancel')],
            'new device' => [new NewDeviceSignInNotification('Chrome on Windows', '203.0.113.x', '12 Oct 2026, 10:00 BST')],
            'api token created' => [new ApiTokenCreatedNotification('publishing-bot')],
            'two-factor enabled' => [new TwoFactorEnabledNotification],
            'two-factor disabled' => [new TwoFactorDisabledNotification],
            'recovery codes regenerated' => [new RecoveryCodesRegeneratedNotification],
            'passkey added' => [new PasskeyAddedNotification('Work laptop')],
            'passkey removed' => [new PasskeyRemovedNotification('Old phone')],
        ];
    }

    #[DataProvider('notifications')]
    public function test_each_notification_is_queued_and_asks_was_this_you(SecurityNotification $notification): void
    {
        $user = User::factory()->make(['email' => 'reader@example.com']);
        $mail = $notification->toMail($user);
        $text = implode("\n", [...$mail->introLines, ...$mail->outroLines, $mail->subject]);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['mail'], $notification->via($user));
        $this->assertStringStartsWith('APES Newsroom: ', $mail->subject);
        $this->assertStringContainsString('Was this you?', $text);
        $this->assertNotEmpty($mail->actionUrl);
        $this->assertDoesNotMatchRegularExpression('/token=|password:|\b\d{6}\b/i', $text);
    }

    private function userWithDeviceHistory(DateTimeInterface $lastSeen): User
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        UserSession::factory()->for($user)->create([
            'browser' => 'Chrome',
            'platform' => 'Windows',
            'device_type' => 'desktop',
            'last_seen_at' => $lastSeen,
        ]);

        return $user;
    }

    private function signIn(User $user, string $userAgent): void
    {
        $this->withHeader('User-Agent', $userAgent)
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));
    }
}
