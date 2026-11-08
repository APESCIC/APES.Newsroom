<?php

namespace Tests\Feature\Staff;

use App\Enums\ContentVisibility;
use App\Enums\MailingList;
use App\Mail\CampaignPostSummaryMail;
use App\Models\Campaign;
use App\Models\Newsletter;
use App\Models\Post;
use App\Models\User;
use App\Support\EmailTemplates\NewsletterTemplateDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\VerifiesNewsletterSenders;
use Tests\TestCase;

class NewsletterTemplatePreviewTest extends TestCase
{
    use RefreshDatabase;
    use VerifiesNewsletterSenders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->verifyNewsletterSenders();
        RateLimiter::clear('test-send:target:newsletter-template:newsletter');
    }

    public function test_preview_renders_the_draft_with_a_real_post(): void
    {
        $staff = User::factory()->staff()->create(['name' => 'Robin Staff']);
        $post = $this->publishedPost();

        $response = $this->actingAs($staff)
            ->postJson('/staff/newsletter-templates/default/preview', $this->draft($post, 'public'))
            ->assertOk()
            ->assertJsonPath('subject', '[TEST] Weekly: Dragons rehomed');
        $html = (string) $response->json('html');

        $this->assertStringContainsString('Hello Robin, news from <strong>APES CIC</strong>.', $html);
        $this->assertStringContainsString('Full story inside', $html);
        $this->assertStringContainsString('The whole secret story.', $html);
        $this->assertStringContainsString('unsubscribe', $html);
    }

    public function test_preview_shows_the_post_as_members_or_paid(): void
    {
        $staff = User::factory()->staff()->create();
        $post = $this->publishedPost();

        $members = $this->actingAs($staff)->postJson('/staff/newsletter-templates/default/preview', $this->draft($post, 'members'))->assertOk()->json('html');
        $paid = $this->actingAs($staff)->postJson('/staff/newsletter-templates/default/preview', $this->draft($post, 'paid'))->assertOk()->json('html');
        $public = $this->actingAs($staff)->postJson('/staff/newsletter-templates/default/preview', $this->draft($post, 'public'))->assertOk()->json('html');

        $this->assertStringContainsString('Members join free.', $members);
        $this->assertStringNotContainsString('The whole secret story.', $members);
        $this->assertStringContainsString('Supporters only.', $paid);
        $this->assertStringContainsString('The whole secret story.', $public);
        $this->assertSame(ContentVisibility::Public, $post->fresh()->visibility, 'Previewing must not change the post.');
    }

    public function test_preview_rejects_unknown_placeholders_and_unpublished_posts(): void
    {
        $staff = User::factory()->staff()->create();
        $post = $this->publishedPost();
        $draft = Post::factory()->create();

        $this->actingAs($staff)
            ->postJson('/staff/newsletter-templates/default/preview', [...$this->draft($post, 'public'), 'intro' => 'Hi {{ secret }}'])
            ->assertStatus(422);
        $this->actingAs($staff)
            ->postJson('/staff/newsletter-templates/default/preview', $this->draft($draft, 'public'))
            ->assertNotFound();
    }

    public function test_members_cannot_preview_or_test(): void
    {
        $member = User::factory()->create();
        $post = $this->publishedPost();

        $this->actingAs($member)->postJson('/staff/newsletter-templates/default/preview', $this->draft($post, 'public'))->assertForbidden();
        $this->actingAs($member)->postJson('/staff/newsletter-templates/default/test', $this->draft($post, 'public'))->assertForbidden();
    }

    public function test_test_send_uses_the_campaign_test_send_and_goes_only_to_the_editor(): void
    {
        Mail::fake();
        $staff = User::factory()->staff()->create(['email' => 'editor@example.org']);
        $post = $this->publishedPost();

        $this->actingAs($staff)
            ->postJson('/staff/newsletter-templates/default/test', [...$this->draft($post, 'public'), 'email' => 'someone-else@example.org'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $campaign = Campaign::query()->sole();
        $this->assertTrue($campaign->is_test);
        $this->assertSame('editor@example.org', $campaign->test_recipient);
        $this->assertSame('Weekly: {{ post_title }}', $campaign->snapshot['template']['subject']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $staff->id, 'action' => 'newsletter_template.test_sent']);

        Mail::assertSent(CampaignPostSummaryMail::class, fn (CampaignPostSummaryMail $mail) => $mail->isTest
            && $mail->hasTo('editor@example.org')
            && $mail->envelope()->subject === '[TEST] Weekly: Dragons rehomed');
        Mail::assertSent(CampaignPostSummaryMail::class, 1);
    }

    public function test_a_newsletter_version_is_tested_from_that_newsletter(): void
    {
        Mail::fake();
        $staff = User::factory()->staff()->create();
        $post = $this->publishedPost();
        $shelter = Newsletter::query()->where('slug', 'apes-shelter-rescue')->firstOrFail();

        $this->actingAs($staff)->postJson("/staff/newsletter-templates/{$shelter->id}/test", $this->draft($post, 'public'))->assertOk();

        $campaign = Campaign::query()->sole();
        $this->assertSame($shelter->id, $campaign->newsletter_id);
        $this->assertSame('APES Shelter & Rescue', $campaign->snapshot['placeholders']['newsletter_name']);
    }

    public function test_test_sends_are_throttled(): void
    {
        Mail::fake();
        config(['mailing.test_send.per_user_per_minute' => 2]);
        $staff = User::factory()->staff()->create();
        $post = $this->publishedPost();

        $this->actingAs($staff)->postJson('/staff/newsletter-templates/default/test', $this->draft($post, 'public'))->assertOk();
        $this->actingAs($staff)->postJson('/staff/newsletter-templates/default/test', $this->draft($post, 'public'))->assertOk();
        $this->actingAs($staff)->postJson('/staff/newsletter-templates/default/test', $this->draft($post, 'public'))
            ->assertStatus(429)
            ->assertJsonPath('ok', false);

        $this->assertSame(2, Campaign::query()->count());
    }

    public function test_the_post_campaign_test_send_route_is_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('staff.posts.campaign.test');

        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
    }

    private function publishedPost(): Post
    {
        return Post::factory()->published()->create([
            'title' => 'Dragons rehomed',
            'excerpt' => 'Six dragons found homes.',
            'visibility' => ContentVisibility::Public,
            'mailing_lists' => [MailingList::ApesCic->value],
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'The whole secret story.']]]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(Post $post, string $visibility): array
    {
        return [
            'subject' => 'Weekly: {{ post_title }}',
            'intro' => 'Hello {{ recipient_first_name }}, news from **{{ newsletter_name }}**.',
            'settings' => [
                ...NewsletterTemplateDefaults::settings(),
                'read_more_label' => 'Full story inside',
                'members_cta' => 'Members join free.',
                'paid_cta' => 'Supporters only.',
            ],
            'post_id' => $post->id,
            'visibility' => $visibility,
        ];
    }
}
