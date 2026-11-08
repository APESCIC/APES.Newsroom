<?php

namespace Tests\Feature\Staff;

use App\Enums\MailingList;
use App\Mail\CampaignPostSummaryMail;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\User;
use App\Services\EmailTemplates\NewsletterTemplateService;
use App\Services\Mailing\CampaignService;
use App\Support\EmailTemplates\NewsletterTemplateDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

class NewsletterTemplateCampaignChoiceTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesNewsletterSenders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->verifyNewsletterSenders();
    }

    public function test_the_post_editor_offers_the_default_and_newsletter_versions(): void
    {
        $staff = User::factory()->staff()->create();
        $shelter = $this->newsletter('apes-shelter-rescue');
        $this->template(NewsletterTemplateDefaults::scopeFor($shelter->id), 'Shelter');

        $this->actingAs($staff)->get('/staff/posts/new')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('newsletterTemplates', [
                ['value' => '', 'label' => 'Automatic: the sending newsletter’s template'],
                ['value' => 'newsletter', 'label' => 'Default newsletter template'],
                ['value' => "newsletter:{$shelter->id}", 'label' => 'APES Shelter & Rescue version'],
            ]));
    }

    public function test_a_post_saves_a_valid_choice_and_rejects_others(): void
    {
        $staff = User::factory()->staff()->create();
        $post = Post::factory()->create(['author_id' => $staff->id]);
        $shelter = $this->newsletter('apes-shelter-rescue');

        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", $this->payload($post, "newsletter:{$shelter->id}"))->assertSessionHasNoErrors();
        $this->assertSame("newsletter:{$shelter->id}", $post->fresh()->newsletter_template);

        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", $this->payload($post->fresh(), ''))->assertSessionHasNoErrors();
        $this->assertNull($post->fresh()->newsletter_template);

        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", $this->payload($post->fresh(), 'system'))->assertSessionHasErrors('newsletter_template');
        $this->actingAs($staff)->patch("/staff/posts/{$post->id}", $this->payload($post->fresh(), 'newsletter:999999'))->assertSessionHasErrors('newsletter_template');
    }

    public function test_a_campaign_uses_its_newsletters_template_by_default_and_the_chosen_one_otherwise(): void
    {
        $admin = User::factory()->admin()->create();
        $cic = $this->newsletter('apes-cic');
        $shelter = $this->newsletter('apes-shelter-rescue');
        $this->template(NewsletterTemplateDefaults::SCOPE_DEFAULT, 'Default {{ post_title }}');
        $this->template(NewsletterTemplateDefaults::scopeFor($cic->id), 'CIC {{ post_title }}');
        $this->template(NewsletterTemplateDefaults::scopeFor($shelter->id), 'Shelter {{ post_title }}');

        $automatic = app(CampaignService::class)->createFromPublishedPost($this->publishedPost(null), $admin);
        $chosen = app(CampaignService::class)->createFromPublishedPost($this->publishedPost("newsletter:{$shelter->id}"), $admin);
        $default = app(CampaignService::class)->createFromPublishedPost($this->publishedPost('newsletter'), $admin);

        $this->assertSame("newsletter:{$cic->id}", $automatic->snapshot['template']['scope']);
        $this->assertSame("newsletter:{$shelter->id}", $chosen->snapshot['template']['scope']);
        $this->assertSame('Shelter {{ post_title }}', $chosen->snapshot['template']['subject']);
        $this->assertSame(NewsletterTemplateDefaults::SCOPE_DEFAULT, $default->snapshot['template']['scope']);
    }

    public function test_without_any_saved_template_the_snapshot_is_unchanged(): void
    {
        $admin = User::factory()->admin()->create();

        $campaign = app(CampaignService::class)->createFromPublishedPost($this->publishedPost('newsletter'), $admin);

        $this->assertArrayNotHasKey('template', $campaign->snapshot);
        $this->assertArrayNotHasKey('placeholders', $campaign->snapshot);
    }

    public function test_editing_a_template_later_does_not_change_sent_campaigns(): void
    {
        Mail::fake();
        $staff = User::factory()->staff()->create();
        $service = app(NewsletterTemplateService::class);
        $service->save(NewsletterTemplateDefaults::SCOPE_DEFAULT, 'Before: {{ post_title }}', 'Original intro', NewsletterTemplateDefaults::settings(), $staff);
        $post = $this->publishedPost(null);

        app(CampaignService::class)->createTestSend($post, $staff, 'reader@example.org');
        $service->save(NewsletterTemplateDefaults::SCOPE_DEFAULT, 'After: {{ post_title }}', 'Changed intro', NewsletterTemplateDefaults::settings(), $staff);

        $campaign = Campaign::query()->sole();
        $this->assertNotNull($campaign->snapshot['template']['version_id']);
        $resent = new CampaignPostSummaryMail($campaign->snapshot, 'https://x.test/u', 'https://x.test/p', 'https://x.test/l', isTest: true);

        $this->assertSame('[TEST] Before: Rescue update', $resent->envelope()->subject);
        $this->assertStringContainsString('Original intro', $resent->render());
        $this->assertStringNotContainsString('Changed intro', $resent->render());
        Mail::assertSent(CampaignPostSummaryMail::class, fn (CampaignPostSummaryMail $mail) => $mail->envelope()->subject === '[TEST] Before: Rescue update');
    }

    private function publishedPost(?string $choice): Post
    {
        return Post::factory()->published()->create([
            'title' => 'Rescue update',
            'email_on_publish' => true,
            'mailing_lists' => [MailingList::ApesCic->value],
            'newsletter_template' => $choice,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Post $post, string $choice): array
    {
        return [
            'title' => $post->title,
            'slug' => $post->slug,
            'channel' => $post->channel->value,
            'editor' => 'editorjs',
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]]],
            'newsletter_template' => $choice,
            'expected_updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    private function newsletter(string $slug): Newsletter
    {
        return Newsletter::query()->where('slug', $slug)->firstOrFail();
    }

    private function template(string $scope, string $subject): EmailTemplate
    {
        return EmailTemplate::query()->create([
            'key' => NewsletterTemplateDefaults::KEY,
            'scope' => $scope,
            'subject' => $subject,
            'body' => '',
            'settings' => NewsletterTemplateDefaults::settings(),
        ]);
    }
}
