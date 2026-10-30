<?php

namespace Tests\Feature;

use App\Enums\MailingList;
use App\Mail\CampaignPostSummaryMail;
use App\Mail\SendingDomainTestMail;
use App\Mail\TransportTestMail;
use App\Models\Newsletter;
use App\Models\User;
use App\Notifications\CampaignPausedNotification;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\ConfirmMailingListNotification;
use App\Notifications\ConfirmNewsletterNotification;
use App\Notifications\IntegrationCheckFailedNotification;
use App\Notifications\MagicLinkNotification;
use App\Notifications\ReauthenticationCodeNotification;
use App\Notifications\Security\AccountLockedNotification;
use App\Notifications\Security\AccountUnlockedNotification;
use App\Notifications\Security\AdminAccountActionNotification;
use App\Notifications\Security\ApiTokenCreatedNotification;
use App\Notifications\Security\EmailChangeRequestedNotification;
use App\Notifications\Security\NewDeviceSignInNotification;
use App\Notifications\Security\PasskeyAddedNotification;
use App\Notifications\Security\PasskeyRemovedNotification;
use App\Notifications\Security\PasswordChangedNotification;
use App\Notifications\Security\RecoveryCodesRegeneratedNotification;
use App\Notifications\Security\TwoFactorDisabledNotification;
use App\Notifications\Security\TwoFactorEnabledNotification;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BrandedEmailsTest extends TestCase
{
    use RefreshDatabase;

    private const LONG_URL = 'https://apesnews.org.uk/account/email/confirm/a1b2c3d4e5a1b2c3d4e5a1b2c3d4e5a1b2c3d4e5a1b2c3d4e5a1b2c3d4e5?signature=f9e8d7c6b5f9e8d7c6b5f9e8d7c6b5';

    /**
     * @return iterable<string, array{Closure(): Notification}>
     */
    public static function notifications(): iterable
    {
        $newsletter = fn () => new Newsletter(['name' => 'APES Shelter & Rescue', 'description' => 'Adoption news.']);

        yield 'confirm newsletter' => [fn () => new ConfirmNewsletterNotification($newsletter(), self::LONG_URL)];
        yield 'confirm mailing list' => [fn () => new ConfirmMailingListNotification(MailingList::ApesShelterRescue, self::LONG_URL)];
        yield 'confirm email change' => [fn () => new ConfirmEmailChangeNotification(self::LONG_URL, 60)];
        yield 'magic link' => [fn () => new MagicLinkNotification(self::LONG_URL)];
        yield 'reauthentication code' => [fn () => new ReauthenticationCodeNotification('482913', 10)];
        yield 'integration check failed' => [fn () => new IntegrationCheckFailedNotification('Mailgun', 'HTTP 401 Unauthorized', '2026-10-30 03:00')];
        yield 'campaign paused' => [fn () => new CampaignPausedNotification(1, 'Rescued dragons', 'SMTP timeout', 12, null)];
        yield 'account locked' => [fn () => new AccountLockedNotification];
        yield 'account unlocked' => [fn () => new AccountUnlockedNotification];
        yield 'admin account action' => [fn () => new AdminAccountActionNotification(AdminAccountActionNotification::TWO_FACTOR_RESET)];
        yield 'api token created' => [fn () => new ApiTokenCreatedNotification('Adoption feed')];
        yield 'email change requested' => [fn () => new EmailChangeRequestedNotification('b***@example.org', self::LONG_URL)];
        yield 'new device sign-in' => [fn () => new NewDeviceSignInNotification('Chrome on Windows', '81.2.144.x', '30 Oct 2026 03:00')];
        yield 'passkey added' => [fn () => new PasskeyAddedNotification('iPhone')];
        yield 'passkey removed' => [fn () => new PasskeyRemovedNotification('YubiKey')];
        yield 'password changed' => [fn () => new PasswordChangedNotification];
        yield 'recovery codes regenerated' => [fn () => new RecoveryCodesRegeneratedNotification];
        yield 'two-factor disabled' => [fn () => new TwoFactorDisabledNotification];
        yield 'two-factor enabled' => [fn () => new TwoFactorEnabledNotification];
    }

    /**
     * @param  Closure(): Notification  $make
     */
    #[DataProvider('notifications')]
    public function test_notifications_render_with_the_soft_glass_theme(Closure $make): void
    {
        $user = User::factory()->make([
            'name' => 'Bartholomew Featherstonehaugh-Montgomery-Smythe',
            'email' => 'a.very.long.address.for.layout.testing@example-newsroom-domain.org.uk',
        ]);

        $message = $make()->toMail($user);
        $html = (string) $message->render();

        $this->assertBranded($html);
        if ($message->actionText === null) {
            $this->assertStringNotContainsString('button-cell', $html);

            return;
        }
        $this->assertStringContainsString('border-radius: 9999px', $html);
        $this->assertMatchesRegularExpression('/class="button-cell"[^>]*bgcolor="#006E70"/', $html);
        $this->assertStringContainsString('class="break-all"', $html);
    }

    public function test_campaign_email_shows_badge_image_button_and_readable_footer_links(): void
    {
        $html = $this->campaign()->render();

        $this->assertBranded($html);
        $this->assertMatchesRegularExpression('/<span class="badge"[^>]*background-color: #00C4CC[^>]*>APES Shelter &amp; Rescue<\/span>/', $html);
        $this->assertStringContainsString('<h1', $html);
        $this->assertMatchesRegularExpression('/<img src="https?:\/\/[^"]+\/media\/dragons\.jpg" class="hero-image"[^>]*alt="Six bearded dragons"/', $html);
        $this->assertStringContainsString('Read the full story', $html);
        $this->assertStringContainsString('https://apesnews.org.uk/r/read-more', $html);
        $this->assertStringContainsString('https://apesnews.org.uk/preferences/abc', $html);
        $this->assertStringContainsString('https://apesnews.org.uk/unsubscribe/abc', $html);
        $this->assertStringContainsString('https://apesnews.org.uk/o/pixel.gif', $html);
        $this->assertStringNotContainsString('TEST SEND', $html);
    }

    public function test_campaign_test_send_keeps_a_clear_banner(): void
    {
        $html = $this->campaign(isTest: true)->render();

        $this->assertMatchesRegularExpression('/<p class="test-banner"[^>]*color: #8A4B0B[^>]*>TEST SEND: this message was not sent to the live mailing list\.<\/p>/', $html);
    }

    public function test_campaign_without_html_or_image_falls_back_to_the_excerpt(): void
    {
        $html = $this->campaign(['html' => null, 'hero_image' => null])->render();

        $this->assertStringContainsString('Six bearded dragons have been adopted.', $html);
        $this->assertStringNotContainsString('hero-image', $html);
    }

    public function test_a_long_newsletter_stays_under_the_gmail_clipping_limit(): void
    {
        $section = '<h2>Section</h2><p>Six bearded dragons rescued in January have all been <a href="https://apesnews.org.uk/adopt">adopted</a> by families across Merseyside.</p><ul><li>One</li><li>Two</li></ul>';
        $html = $this->campaign(['html' => str_repeat($section, 60)])->render();

        $this->assertLessThan(102 * 1024, strlen($html), 'Gmail clips messages over 102 KB, hiding the unsubscribe link.');
        $this->assertStringContainsString('https://apesnews.org.uk/unsubscribe/abc', $html);
    }

    public function test_buttons_keep_their_padding_in_outlook(): void
    {
        $html = $this->campaign()->render();

        $this->assertMatchesRegularExpression('/class="button-cell"[^>]*mso-padding-alt: 12px 28px/', $html);
    }

    public function test_admin_test_emails_use_the_theme(): void
    {
        $this->assertBranded((new TransportTestMail('newsroom@apesnews.org.uk', 'APES Newsroom', 'Mailgun API', 'Newsletters', 'Staff Demo'))->render());
        $this->assertBranded((new SendingDomainTestMail('news@shelter.apesnews.org.uk', 'shelter.apesnews.org.uk', 'Staff Demo'))->render());
    }

    public function test_plain_text_part_carries_the_organisation_footer(): void
    {
        $plain = (string) app(Markdown::class)->renderText('mail.transport-test', [
            'purposeLabel' => 'Newsletters',
            'fromAddress' => 'newsroom@apesnews.org.uk',
            'transportLabel' => 'Mailgun API',
            'sentAt' => '30 Oct 2026 03:00',
            'requestedBy' => 'Staff Demo',
        ]);

        $this->assertStringContainsString('40 Morris Street, St Helens, Merseyside, WA9 3EN', $plain);
        $this->assertStringContainsString('CIC No: 16253848', $plain);
    }

    private function assertBranded(string $html): void
    {
        $this->assertMatchesRegularExpression('/<img src="[^"]*brand\/apes-logo-masthead\.png" class="logo"[^>]*alt="APES Newsroom"/', $html);
        $this->assertStringContainsString('bgcolor="#E8F6F6"', $html);
        $this->assertStringContainsString('border-top: 4px solid #00C4CC', $html);
        $this->assertStringContainsString('40 Morris Street, St Helens, Merseyside, WA9 3EN', $html);
        $this->assertStringContainsString('CIC No: 16253848', $html);
        $this->assertStringNotContainsString('All rights reserved', $html);
        $this->assertDoesNotMatchRegularExpression('/(?<![-\w])color:\s*#00C4CC/i', $html, 'The accent must never be a text colour.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function campaign(array $overrides = [], bool $isTest = false): CampaignPostSummaryMail
    {
        return new CampaignPostSummaryMail(
            snapshot: array_merge([
                'title' => 'Rescued bearded dragons find new homes',
                'excerpt' => 'Six bearded dragons have been adopted.',
                'html' => '<p>Six bearded dragons rescued in January have all been adopted.</p>',
                'hero_image' => '/media/dragons.jpg',
                'hero_image_alt' => 'Six bearded dragons',
                'author' => 'Staff Demo',
                'channel_label' => 'APES Shelter & Rescue',
                'published_at' => '2026-10-30T09:00:00+00:00',
                'read_more_url' => 'https://apesnews.org.uk/articles/rescued-dragons',
            ], $overrides),
            unsubscribeUrl: 'https://apesnews.org.uk/unsubscribe/abc',
            preferencesUrl: 'https://apesnews.org.uk/preferences/abc',
            listUnsubscribeUrl: 'https://apesnews.org.uk/unsubscribe/abc/one-click',
            isTest: $isTest,
            openPixelUrl: 'https://apesnews.org.uk/o/pixel.gif',
            trackedReadMoreUrl: 'https://apesnews.org.uk/r/read-more',
        );
    }
}
