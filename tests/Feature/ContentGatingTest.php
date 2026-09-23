<?php

namespace Tests\Feature;

use App\Enums\ContentVisibility;
use App\Enums\MembershipStatus;
use App\Enums\PostStatus;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Mailing\CampaignService;
use App\Services\Membership\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContentGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guest_sees_gate_not_html_for_members_only_post(): void
    {
        $post = Post::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
            'visibility' => ContentVisibility::Members,
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Secret body']]]],
            'excerpt' => 'Public teaser',
        ]);

        $this->get('/articles/'.$post->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Articles/Show')
                ->where('article.gated', true)
                ->where('article.html', null)
                ->where('article.excerpt', 'Public teaser')
                ->where('article.gate.reason', 'members'));
    }

    public function test_free_member_sees_members_content_but_not_paid(): void
    {
        $user = User::factory()->create();
        app(MembershipService::class)->ensureMembership($user);

        $membersPost = Post::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
            'visibility' => ContentVisibility::Members,
            'slug' => 'members-post',
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Members body']]]],
        ]);
        $paidPost = Post::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
            'visibility' => ContentVisibility::Paid,
            'slug' => 'paid-post',
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Paid body']]]],
        ]);

        $this->actingAs($user)
            ->get('/articles/'.$membersPost->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('article.gated', false)
                ->where('article.html', fn (?string $html) => is_string($html) && str_contains($html, 'Members body')));

        $this->actingAs($user)
            ->get('/articles/'.$paidPost->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('article.gated', true)
                ->where('article.html', null)
                ->where('article.gate.reason', 'paid'));
    }

    public function test_paying_member_sees_paid_page_body(): void
    {
        $user = User::factory()->create();
        $membership = app(MembershipService::class)->ensureMembership($user);
        $membership->update(['status' => MembershipStatus::Active]);

        $page = Page::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
            'visibility' => ContentVisibility::Paid,
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Paid page']]]],
        ]);

        $this->actingAs($user)
            ->get('/pages/'.$page->slug)
            ->assertOk()
            ->assertInertia(fn (Assert $pageAssert) => $pageAssert
                ->where('page.gated', false)
                ->where('page.html', fn (?string $html) => is_string($html) && str_contains($html, 'Paid page')));
    }

    public function test_publish_email_snapshot_omits_html_for_gated_post(): void
    {
        $post = Post::factory()->create([
            'visibility' => ContentVisibility::Members,
            'excerpt' => 'Teaser only',
            'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Secret']]]],
        ]);

        $snapshot = app(CampaignService::class)->buildSnapshot($post);

        $this->assertNull($snapshot['html']);
        $this->assertStringContainsString('members', strtolower($snapshot['excerpt']));
        $this->assertSame('members', $snapshot['visibility']);
    }
}
