<?php

namespace Tests\Feature;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignStatus;
use App\Enums\MembershipStatus;
use App\Enums\PostStatus;
use App\Http\Controllers\Mailing\CampaignTrackingController;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ContentView;
use App\Models\MembershipPlan;
use App\Models\Post;
use App\Models\User;
use App\Services\Membership\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MembershipAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_anonymous_article_view_is_recorded_without_user(): void
    {
        $post = Post::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
        ]);

        $this->get('/articles/'.$post->slug)->assertOk();

        $view = ContentView::query()->first();
        $this->assertNotNull($view);
        $this->assertSame($post->id, $view->post_id);
        $this->assertNull($view->user_id);
        $this->assertStringContainsString('/articles/', $view->path);
    }

    public function test_signed_in_view_links_to_user(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)->get('/articles/'.$post->slug)->assertOk();

        $this->assertDatabaseHas('content_views', [
            'post_id' => $post->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_tracking_open_and_click_update_recipient_once(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create(['author_id' => $admin->id]);
        $campaign = Campaign::query()->create([
            'post_id' => $post->id,
            'created_by' => $admin->id,
            'idempotency_key' => 'analytics-test-campaign',
            'lists' => [],
            'snapshot' => ['title' => 'Hello', 'read_more_url' => url('/articles/'.$post->slug)],
            'status' => CampaignStatus::Completed,
            'is_test' => false,
        ]);
        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => 'reader@example.com',
            'status' => CampaignRecipientStatus::Accepted,
            'attempts' => 1,
            'idempotency_key' => 'analytics-test-recipient',
            'accepted_at' => now(),
        ]);

        $openUrl = CampaignTrackingController::openUrl($recipient);
        $this->get($openUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif');

        $recipient->refresh();
        $this->assertNotNull($recipient->opened_at);
        $openedAt = $recipient->opened_at->toIso8601String();

        $this->get($openUrl)->assertOk();
        $recipient->refresh();
        $this->assertSame($openedAt, $recipient->opened_at->toIso8601String());

        $clickUrl = CampaignTrackingController::clickUrl($recipient, url('/articles/'.$post->slug));
        $this->get($clickUrl)->assertRedirect(url('/articles/'.$post->slug));

        $recipient->refresh();
        $this->assertNotNull($recipient->clicked_at);

        $this->get(URL::temporarySignedRoute(
            'mailing.track.click',
            now()->addDays(30),
            ['recipient' => $recipient->id, 'u' => 'javascript:alert(1)'],
        ))->assertRedirect(url('/'));
    }

    public function test_unsigned_tracking_urls_are_forbidden(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create(['author_id' => $admin->id]);
        $campaign = Campaign::query()->create([
            'post_id' => $post->id,
            'created_by' => $admin->id,
            'idempotency_key' => 'analytics-unsigned',
            'lists' => [],
            'snapshot' => ['title' => 'Hello'],
            'status' => CampaignStatus::Completed,
            'is_test' => false,
        ]);
        $recipient = CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => 'reader@example.com',
            'status' => CampaignRecipientStatus::Accepted,
            'attempts' => 1,
            'idempotency_key' => 'analytics-unsigned-recipient',
            'accepted_at' => now(),
        ]);

        $this->get(route('mailing.track.open', $recipient))->assertForbidden();
        $this->get(route('mailing.track.click', ['recipient' => $recipient, 'u' => url('/')]))->assertForbidden();
    }

    public function test_staff_metrics_dashboard_summarises_web_mail_and_subs(): void
    {
        $staff = User::factory()->staff()->create();
        $member = User::factory()->create();
        $membership = app(MembershipService::class)->ensureMembership($member);
        $plan = MembershipPlan::query()->where('slug', 'monthly')->firstOrFail();
        $membership->update([
            'status' => MembershipStatus::Active,
            'membership_plan_id' => $plan->id,
            'interval' => 'month',
        ]);

        ContentView::query()->create([
            'path' => '/articles/demo',
            'user_id' => null,
            'viewed_at' => now(),
        ]);
        ContentView::query()->create([
            'path' => '/articles/demo',
            'user_id' => $member->id,
            'viewed_at' => now(),
        ]);

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->published()->create(['author_id' => $admin->id]);
        $campaign = Campaign::query()->create([
            'post_id' => $post->id,
            'created_by' => $admin->id,
            'idempotency_key' => 'metrics-campaign',
            'lists' => [],
            'snapshot' => ['title' => 'Metrics'],
            'status' => CampaignStatus::Completed,
            'is_test' => false,
        ]);
        CampaignRecipient::query()->create([
            'campaign_id' => $campaign->id,
            'email' => 'a@example.com',
            'status' => CampaignRecipientStatus::Accepted,
            'attempts' => 1,
            'idempotency_key' => 'metrics-a',
            'accepted_at' => now(),
            'opened_at' => now(),
            'clicked_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get(route('staff.metrics.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/Metrics/Index')
                ->where('metrics.web.views_7d', 2)
                ->where('metrics.web.signed_in_share_7d', fn ($v) => (float) $v === 50.0)
                ->where('metrics.newsletters.sent', 1)
                ->where('metrics.newsletters.opened', 1)
                ->where('metrics.newsletters.clicked', 1)
                ->where('metrics.subscriptions.active', 1)
                ->where('metrics.subscriptions.mrr_pence', $plan->amount_pence));
    }

    public function test_guests_cannot_view_staff_metrics(): void
    {
        $this->get(route('staff.metrics.index'))->assertRedirect();
    }

    public function test_cookie_notice_discloses_first_party_metrics(): void
    {
        $this->get(route('legal.cookies'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Legal/Cookies'));
    }
}
