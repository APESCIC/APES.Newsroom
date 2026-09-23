<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\ContentView;
use App\Models\Post;
use App\Models\User;
use App\Services\Membership\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MembershipCrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_staff_can_list_filter_and_see_recent_reads(): void
    {
        $staff = User::factory()->staff()->create();

        $free = User::factory()->create(['name' => 'Free Reader', 'email' => 'free@example.com']);
        app(MembershipService::class)->ensureMembership($free);

        $paying = User::factory()->create(['name' => 'Paying Reader', 'email' => 'pay@example.com']);
        $membership = app(MembershipService::class)->ensureMembership($paying);
        $membership->update(['status' => MembershipStatus::Active]);

        $post = Post::factory()->create([
            'author_id' => $staff->id,
            'status' => PostStatus::Published,
            'published_at' => now()->subMinute(),
        ]);

        ContentView::query()->create([
            'path' => '/articles/'.$post->slug,
            'post_id' => $post->id,
            'user_id' => $paying->id,
            'viewed_at' => now(),
        ]);

        $this->actingAs($staff)
            ->get(route('staff.members.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/Members/Index')
                ->has('members.data', 2));

        $this->actingAs($staff)
            ->get(route('staff.members.index', ['status' => 'paying']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('members.data', 1)
                ->where('members.data.0.email', 'pay@example.com')
                ->where('members.data.0.is_paying', true)
                ->where('members.data.0.recent_reads.0.path', '/articles/'.$post->slug));

        $this->actingAs($staff)
            ->get(route('staff.members.index', ['q' => 'Free Reader']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('members.data', 1)
                ->where('members.data.0.email', 'free@example.com')
                ->where('members.data.0.is_paying', false));
    }

    public function test_staff_crm_excludes_staff_accounts(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'editor@example.com']);
        $member = User::factory()->create(['email' => 'member@example.com']);
        app(MembershipService::class)->ensureMembership($member);

        $this->actingAs($staff)
            ->get(route('staff.members.index', ['q' => 'editor@example.com']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('members.data', 0));

        $this->assertSame(Role::Staff, $staff->role);
    }

    public function test_guests_cannot_access_member_crm(): void
    {
        $this->get(route('staff.members.index'))->assertRedirect();
    }
}
