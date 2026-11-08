<?php

namespace Tests\Feature\Staff;

use App\Mail\CampaignPostSummaryMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * With no newsletter template saved, the campaign email must stay byte for
 * byte what it was before newsletter templates existed (v1.29.0).
 */
class NewsletterTemplateDefaultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string, array<string, mixed>, bool}>
     */
    public static function variants(): iterable
    {
        yield 'public post' => ['public', [], false];
        yield 'public test send' => ['public-test', [], true];
        yield 'members post' => ['members', [
            'html' => null,
            'visibility' => 'members',
            'excerpt' => "Six bearded dragons have been adopted.\n\nThis article is for members. Create a free account to read the full post.",
        ], false];
        yield 'no hero image' => ['no-hero', ['hero_image' => null], false];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('variants')]
    public function test_unedited_default_renders_exactly_as_before(string $fixture, array $overrides, bool $isTest): void
    {
        $html = $this->normalise($this->mail($overrides, $isTest)->render());
        $path = base_path("tests/fixtures/mail/campaign-post-summary-{$fixture}.html");

        if (! is_file($path)) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $html);
            $this->markTestIncomplete("Golden fixture written to {$path}; rerun to compare.");
        }

        $this->assertSame($this->normalise((string) file_get_contents($path)), $html);
    }

    private function normalise(string $html): string
    {
        return str_replace("\r\n", "\n", $html);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mail(array $overrides, bool $isTest): CampaignPostSummaryMail
    {
        return new CampaignPostSummaryMail(
            snapshot: array_merge([
                'title' => 'Rescued bearded dragons find new homes',
                'excerpt' => 'Six bearded dragons have been adopted.',
                'html' => '<p>Six bearded dragons rescued in January have all been adopted.</p>',
                'visibility' => 'public',
                'hero_image' => 'https://apesnews.org.uk/media/dragons.jpg',
                'hero_image_alt' => 'Six bearded dragons',
                'author' => 'Staff Demo',
                'channel' => 'shelter',
                'channel_label' => 'APES Shelter & Rescue',
                'published_at' => '2026-10-30T09:00:00+00:00',
                'read_more_url' => 'https://apesnews.org.uk/articles/rescued-dragons',
                'slug' => 'rescued-dragons',
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
