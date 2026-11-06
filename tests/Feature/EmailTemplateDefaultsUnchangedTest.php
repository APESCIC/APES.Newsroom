<?php

namespace Tests\Feature;

use App\Enums\MailingList;
use App\Models\Newsletter;
use App\Models\User;
use App\Notifications\ConfirmMailingListNotification;
use App\Notifications\MagicLinkNotification;
use App\Notifications\Security\PasswordChangedNotification;
use App\Notifications\Security\TwoFactorEnabledNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Snapshot coverage: with no DB overrides, templated notifications still match
 * today's rendered subjects and bodies (#652).
 */
class EmailTemplateDefaultsUnchangedTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://apesnews.org.uk/example/token?signature=abc';

    /**
     * @return iterable<string, array{Notification, string, list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'magic link' => [
            new MagicLinkNotification(self::URL),
            'Your APES Newsroom sign-in link',
            ['Click the button below to sign in', 'Sign in'],
        ];

        yield 'confirm mailing list' => [
            new ConfirmMailingListNotification(MailingList::ApesShelterRescue, self::URL),
            'Confirm your APES Shelter & Rescue subscription',
            ['You asked to join the APES Shelter &amp; Rescue mailing list', 'Confirm subscription'],
        ];

        yield 'two-factor enabled' => [
            new TwoFactorEnabledNotification,
            'APES Newsroom: two-factor authentication turned on',
            ['Two-factor authentication was turned on', 'Was this you?'],
        ];

        yield 'password changed' => [
            new PasswordChangedNotification,
            'APES Newsroom: your password was changed',
            ['The password for your APES Newsroom account was changed', 'Was this you?'],
        ];
    }

    /**
     * @param  list<string>  $bodySnippets
     */
    #[DataProvider('cases')]
    public function test_default_render_matches_expected_wording(Notification $notification, string $subject, array $bodySnippets): void
    {
        $user = User::factory()->make(['email' => 'reader@example.org']);
        $message = $notification->toMail($user);

        $this->assertSame($subject, $message->subject);
        $html = (string) $message->render();
        foreach ($bodySnippets as $snippet) {
            $this->assertStringContainsString($snippet, $html);
        }
    }

    #[Test]
    public function test_framework_verify_and_reset_still_render_without_overrides(): void
    {
        $user = User::factory()->create(['email' => 'verify@example.org']);

        $verify = (new VerifyEmail)->toMail($user);
        $this->assertSame('Verify your email address', $verify->subject);
        $this->assertStringContainsString('verify', strtolower((string) $verify->render()));

        $reset = (new ResetPassword('test-token'))->toMail($user);
        $this->assertSame('Reset your password', $reset->subject);
        $this->assertStringContainsString('reset', strtolower((string) $reset->render()));
    }

    #[Test]
    public function test_newsletter_confirm_default_subject(): void
    {
        $newsletter = new Newsletter(['name' => 'APES Shelter & Rescue', 'description' => 'Adoption news.']);
        $user = User::factory()->make();
        $message = (new \App\Notifications\ConfirmNewsletterNotification($newsletter, self::URL))->toMail($user);

        $this->assertSame('Confirm your APES Shelter & Rescue subscription', $message->subject);
    }
}
