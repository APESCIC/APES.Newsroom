<?php

namespace Tests\Feature\Staff;

use App\Mail\CampaignPostSummaryMail;
use App\Models\MailingContact;
use App\Services\EmailTemplates\NewsletterTemplates;
use App\Support\EmailTemplates\NewsletterTemplateDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterTemplateRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_templated_email_renders_intro_sign_off_label_and_subject(): void
    {
        $mail = $this->mail([
            'subject' => 'News: {{ post_title }}',
            'intro' => 'Hi {{ recipient_first_name }}, here is the latest from **{{ newsletter_name }}**.',
            'settings' => ['sign_off' => "With thanks,  \nThe {{ newsletter_name }} team", 'read_more_label' => 'Read more on {{ site_name }}'],
        ], firstName: 'Sam');

        $html = $mail->render();

        $this->assertSame('News: Rescued bearded dragons find new homes', $mail->envelope()->subject);
        $this->assertStringContainsString('Hi Sam, here is the latest from <strong>APES Shelter &amp; Rescue</strong>.', $html);
        $this->assertStringContainsString('The APES Shelter &amp; Rescue team', $html);
        $this->assertStringContainsString('Read more on '.config('app.name'), $html);
        $this->assertStringNotContainsString('Read the full story', $html);
    }

    public function test_test_sends_keep_the_test_prefix_and_banner(): void
    {
        $mail = $this->mail(['subject' => '{{ post_title }}'], isTest: true);

        $this->assertSame('[TEST] Rescued bearded dragons find new homes', $mail->envelope()->subject);
        $this->assertStringContainsString('TEST SEND: this message was not sent to the live mailing list.', $mail->render());
    }

    public function test_placeholder_values_and_markdown_are_escaped(): void
    {
        $html = $this->mail([
            'intro' => 'Hello {{ recipient_first_name }} <script>alert(1)</script> [x](javascript:alert(1))',
        ], firstName: '<img src=x onerror=alert(1)>')->render();

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_unsubscribe_preferences_and_list_unsubscribe_are_always_present(): void
    {
        $mail = $this->mail([
            'intro' => '',
            'settings' => ['sign_off' => '', 'show_hero' => false, 'show_full_post' => false],
        ]);

        $html = $mail->render();
        $headers = $mail->headers()->text;

        $this->assertStringContainsString('https://apesnews.org.uk/unsubscribe/abc', $html);
        $this->assertStringContainsString('https://apesnews.org.uk/preferences/abc', $html);
        $this->assertSame('<https://apesnews.org.uk/unsubscribe/abc/one-click>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    public function test_section_switches_hide_the_hero_and_show_the_excerpt_instead_of_the_post(): void
    {
        $html = $this->mail(['settings' => ['show_hero' => false, 'show_full_post' => false]])->render();

        $this->assertStringNotContainsString('hero-image', $html);
        $this->assertStringNotContainsString('rescued in January have all been adopted.</p>', $html);
        $this->assertStringContainsString('Six bearded dragons have been adopted.', $html);
    }

    public function test_excerpt_can_be_shown_above_the_full_post(): void
    {
        $html = $this->mail(['settings' => ['show_excerpt' => true]])->render();

        $this->assertStringContainsString('class="standfirst"', $html);
        $this->assertStringContainsString('rescued in January have all been adopted.', $html);
    }

    public function test_an_unusable_stored_template_falls_back_to_the_built_in_email(): void
    {
        $html = $this->mail(['intro' => 'Hi {{ not_allowed }}'])->render();

        $this->assertStringContainsString('Read the full story', $html);
        $this->assertStringNotContainsString('not_allowed', $html);
    }

    public function test_first_name_comes_from_the_mailing_contact_or_falls_back(): void
    {
        $templates = app(NewsletterTemplates::class);
        MailingContact::query()->create(['email' => 'sam@example.org', 'name' => 'Sam Taylor']);

        $this->assertSame('Sam', $templates->firstNameFor('SAM@example.org'));
        $this->assertSame('there', $templates->firstNameFor('nobody@example.org'));
    }

    /**
     * @param  array<string, mixed>  $template
     */
    private function mail(array $template, bool $isTest = false, ?string $firstName = null): CampaignPostSummaryMail
    {
        $templates = app(NewsletterTemplates::class);
        $post = [
            'title' => 'Rescued bearded dragons find new homes',
            'excerpt' => 'Six bearded dragons have been adopted.',
            'author' => 'Staff Demo',
            'channel_label' => 'APES Shelter & Rescue',
            'read_more_url' => 'https://apesnews.org.uk/articles/rescued-dragons',
        ];

        return new CampaignPostSummaryMail(
            snapshot: [
                ...$post,
                'html' => '<p>Six bearded dragons rescued in January have all been adopted.</p>',
                'visibility' => 'public',
                'hero_image' => 'https://apesnews.org.uk/media/dragons.jpg',
                'hero_image_alt' => 'Six bearded dragons',
                'published_at' => '2026-10-30T09:00:00+00:00',
                'template' => $templates->draft(
                    NewsletterTemplateDefaults::SCOPE_DEFAULT,
                    (string) ($template['subject'] ?? NewsletterTemplateDefaults::subject()),
                    (string) ($template['intro'] ?? ''),
                    (array) ($template['settings'] ?? []),
                ),
                'placeholders' => [...$templates->postPlaceholders($post, null), 'newsletter_name' => 'APES Shelter & Rescue'],
            ],
            unsubscribeUrl: 'https://apesnews.org.uk/unsubscribe/abc',
            preferencesUrl: 'https://apesnews.org.uk/preferences/abc',
            listUnsubscribeUrl: 'https://apesnews.org.uk/unsubscribe/abc/one-click',
            isTest: $isTest,
            recipientFirstName: $firstName,
        );
    }
}
