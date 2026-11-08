<?php

namespace Tests\Feature\Staff;

use App\Enums\CampaignRecipientStatus;
use App\Enums\MailingList;
use App\Jobs\SendCampaignRecipientJob;
use App\Mail\CampaignPostSummaryMail;
use App\Models\CampaignRecipient;
use App\Models\Post;
use App\Models\User;
use App\Services\EmailTemplates\NewsletterTemplateService;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\CampaignService;
use App\Services\Mailing\ConsentService;
use App\Support\EmailTemplates\NewsletterTemplateDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

/**
 * No newsletter template can remove or hide the unsubscribe and preferences
 * links or the List-Unsubscribe headers.
 */
class NewsletterTemplateUnsubscribeLockedTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesNewsletterSenders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifyNewsletterSenders();
    }

    public function test_the_most_stripped_down_template_still_sends_unsubscribe_links_and_headers(): void
    {
        Mail::fake();
        $staff = User::factory()->staff()->create();
        app(NewsletterTemplateService::class)->save(NewsletterTemplateDefaults::SCOPE_DEFAULT, '{{ post_title }}', '', [
            ...NewsletterTemplateDefaults::settings(),
            'sign_off' => '',
            'show_hero' => false,
            'show_excerpt' => false,
            'show_full_post' => false,
        ], $staff);

        $campaign = app(CampaignService::class)->createTestSend($this->lockedPost(), $staff, 'reader@example.org');
        $this->assertIsArray($campaign->snapshot['template']);

        Mail::assertSent(CampaignPostSummaryMail::class, function (CampaignPostSummaryMail $mail): bool {
            $html = $mail->render();
            $headers = $mail->headers()->text;

            return str_contains($html, 'mailing/unsubscribe')
                && str_contains($html, 'preferences')
                && str_contains($html, '>unsubscribe</a>')
                && str_starts_with($headers['List-Unsubscribe'], '<http')
                && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
        });
        $this->assertSame(CampaignRecipientStatus::Accepted, CampaignRecipient::query()->sole()->status);
    }

    public function test_templates_cannot_reference_or_hide_the_unsubscribe_links(): void
    {
        $staff = User::factory()->staff()->create();
        $service = app(NewsletterTemplateService::class);

        foreach (['{{ unsubscribe_url }}', '{{ preferences_url }}', '{{ list_unsubscribe }}'] as $tag) {
            try {
                $service->save(NewsletterTemplateDefaults::SCOPE_DEFAULT, '{{ post_title }}', "Bye {$tag}", NewsletterTemplateDefaults::settings(), $staff);
                $this->fail("{$tag} should be rejected.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $service->save(NewsletterTemplateDefaults::SCOPE_DEFAULT, '{{ post_title }}', '<style>.subcopy{display:none}</style><div style="display:none">x</div>', [
            ...NewsletterTemplateDefaults::settings(),
            'sign_off' => '<!-- <x-mail::subcopy> -->',
        ], $staff);

        Mail::fake();
        app(CampaignService::class)->createTestSend($this->lockedPost(), $staff, 'reader@example.org');

        Mail::assertSent(CampaignPostSummaryMail::class, function (CampaignPostSummaryMail $mail): bool {
            $html = $mail->render();

            return ! str_contains($html, 'display:none')
                && ! str_contains($html, '.subcopy{')
                && str_contains($html, '>unsubscribe</a>');
        });
    }

    public function test_the_send_job_never_drops_the_headers_for_templated_campaigns(): void
    {
        Mail::fake();
        $staff = User::factory()->staff()->create();
        app(NewsletterTemplateService::class)->save(NewsletterTemplateDefaults::SCOPE_DEFAULT, 'Hi', 'Intro', NewsletterTemplateDefaults::settings(), $staff);
        $campaign = app(CampaignService::class)->createTestSend($this->lockedPost(), $staff, 'reader@example.org');
        $recipient = CampaignRecipient::query()->where('campaign_id', $campaign->id)->sole();
        $recipient->update(['status' => CampaignRecipientStatus::Queued]);

        (new SendCampaignRecipientJob($recipient->id))->handle(app(ConsentService::class), app(CampaignSender::class));

        Mail::assertSent(CampaignPostSummaryMail::class, fn (CampaignPostSummaryMail $mail) => isset($mail->headers()->text['List-Unsubscribe']));
    }

    private function lockedPost(): Post
    {
        return Post::factory()->published()->create([
            'title' => 'Locked footer check',
            'mailing_lists' => [MailingList::ApesCic->value],
        ]);
    }
}
