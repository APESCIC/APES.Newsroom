<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\ModerationReport;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_only_admins_and_super_admins_can_open_the_admin_dashboard(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->staff()->create())->get('/admin')->assertForbidden();

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)
                ->get('/admin')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard'));
        }
    }

    public function test_dashboard_shows_unfiltered_moderation_counts_latest_release_and_audit_events(): void
    {
        $admin = User::factory()->admin()->create();

        Profile::query()->create(['user_id' => User::factory()->create()->id, 'display_name' => 'Pending', 'moderation_status' => ModerationStatus::Pending]);
        Profile::query()->create(['user_id' => User::factory()->create()->id, 'display_name' => 'Pending 2', 'moderation_status' => ModerationStatus::Pending]);
        Profile::query()->create(['user_id' => User::factory()->create()->id, 'display_name' => 'Suspended', 'moderation_status' => ModerationStatus::Suspended]);
        $post = Post::factory()->published()->create(['author_id' => $admin->id]);
        Comment::query()->create([
            'post_id' => $post->id,
            'user_id' => User::factory()->create()->id,
            'body' => 'Hello',
            'body_hash' => hash('sha256', 'Hello'),
            'moderation_status' => ModerationStatus::Pending,
        ]);
        ModerationReport::query()->create([
            'reporter_id' => User::factory()->create()->id,
            'reportable_type' => Post::class,
            'reportable_id' => $post->id,
            'reason' => 'Spam',
        ]);

        Release::query()->delete();
        Release::factory()->create(['version' => 'v1.0.0', 'slug' => 'release-v100', 'is_published' => true, 'released_at' => '2026-01-01']);
        Release::factory()->create(['version' => 'v1.21.0', 'slug' => 'release-v1210', 'is_published' => true, 'is_current' => true, 'released_at' => '2026-10-27', 'summary' => 'Dashboards.']);
        Release::factory()->create(['version' => 'v9.9.9', 'slug' => 'release-v999', 'is_published' => false, 'released_at' => '2026-12-01']);

        foreach (range(1, 10) as $i) {
            AuditLog::query()->create(['actor_id' => $admin->id, 'action' => "settings.updated.{$i}"]);
        }

        $this->actingAs($admin)
            ->get('/admin')
            ->assertInertia(fn (Assert $page) => $page
                ->where('moderation', ['profiles' => 2, 'comments' => 1, 'reports' => 1, 'suspended' => 1])
                ->where('release.version', 'v1.21.0')
                ->where('release.summary', 'Dashboards.')
                ->has('audit', 8)
                ->where('audit.0.action', 'settings.updated.10')
                ->where('audit.0.actor', $admin->name));
    }

    public function test_dashboard_copes_with_no_release_and_no_moderation_work(): void
    {
        Release::query()->delete();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('moderation', ['profiles' => 0, 'comments' => 0, 'reports' => 0, 'suspended' => 0])
                ->where('release', null));
    }
}
