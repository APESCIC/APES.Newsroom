<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChangeLogHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_hub_lists_only_published_releases(): void
    {
        Release::factory()->published()->current()->create([
            'version' => 'v1.1.1',
            'slug' => 'release-v111',
            'theme' => 'Change Log Hub',
            'summary' => 'Public hub release',
        ]);
        Release::factory()->create([
            'version' => 'v9.9.9',
            'slug' => 'release-v999',
            'summary' => 'Draft should stay hidden',
        ]);

        $this->get('/change-log-hub')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ChangeLogHub/Index')
                ->has('releases', 1)
                ->where('releases.0.version', 'v1.1.1')
                ->where('current.version', 'v1.1.1')
                ->where('current.theme', 'Change Log Hub'));
    }

    public function test_sitemap_includes_change_log_hub(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/change-log-hub'), false);
    }

    public function test_footer_shared_props_expose_current_release(): void
    {
        Release::factory()->published()->current()->create([
            'version' => 'v1.1.1',
            'slug' => 'release-v111',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentRelease.version', 'v1.1.1')
                ->where('currentRelease.slug', 'release-v111'));
    }

    public function test_guest_cannot_access_admin_releases(): void
    {
        $this->get('/admin/releases')->assertRedirect('/login');
    }

    public function test_staff_cannot_access_admin_releases(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->get('/admin/releases')->assertForbidden();
    }

    public function test_admin_can_create_published_release(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/releases', [
            'version' => 'v1.2.0',
            'previous_version' => 'v1.1.1',
            'released_at' => '2026-09-18',
            'channel' => 'stable',
            'version_type' => 'minor stable',
            'theme' => 'Editor depth',
            'is_current' => '1',
            'is_published' => '1',
            'change_types' => ['added', 'changed'],
            'topic_tags' => ['public-facing'],
            'summary' => 'Added richer Editor.js cards.',
            'detailed_changes_text' => "Added media cards.\nImproved tag archives.",
            'affected_areas_text' => "Website: APES Newsroom\nPage or route: /articles/{slug}",
            'version_decision_text' => "Previous version: v1.1.1\nNew version: v1.2.0",
            'validation_text' => "Checks run: composer test\nManual checks completed: editor review",
        ]);

        $response->assertRedirect(route('admin.releases.index'));

        $this->assertDatabaseHas('releases', [
            'version' => 'v1.2.0',
            'slug' => 'release-v120',
            'is_published' => true,
            'is_current' => true,
        ]);
    }

    public function test_admin_cannot_publish_without_structured_sections(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/releases', [
            'version' => 'v1.2.1',
            'released_at' => '2026-09-18',
            'channel' => 'stable',
            'is_published' => '1',
            'summary' => 'Incomplete release',
            'detailed_changes_text' => '',
            'affected_areas_text' => '',
            'version_decision_text' => '',
            'validation_text' => '',
        ])->assertSessionHasErrors(['detailed_changes', 'affected_areas', 'version_decision', 'validation']);
    }

    public function test_marking_current_clears_previous_current(): void
    {
        $admin = User::factory()->admin()->create();
        $previous = Release::factory()->published()->current()->create([
            'version' => 'v1.0.0',
            'slug' => 'release-v100',
        ]);

        $this->actingAs($admin)->post('/admin/releases', [
            'version' => 'v1.0.1',
            'released_at' => '2026-09-18',
            'channel' => 'beta',
            'is_current' => '1',
            'is_published' => '1',
            'change_types' => ['fixed'],
            'summary' => 'Patch release',
            'detailed_changes_text' => 'Fixed footer link.',
            'affected_areas_text' => 'Footer',
            'version_decision_text' => 'Patch bump',
            'validation_text' => 'Manual check',
        ])->assertRedirect();

        $this->assertFalse($previous->fresh()->is_current);
        $this->assertTrue(Release::query()->where('version', 'v1.0.1')->value('is_current'));
    }

    public function test_admin_can_update_and_delete_release(): void
    {
        $admin = User::factory()->admin()->create();
        $release = Release::factory()->published()->create([
            'version' => 'v1.0.2',
            'slug' => 'release-v102',
            'summary' => 'Old summary',
        ]);

        $this->actingAs($admin)->put('/admin/releases/'.$release->id, [
            'version' => 'v1.0.2',
            'released_at' => '2026-09-18',
            'channel' => 'stable',
            'is_published' => '1',
            'summary' => 'Updated summary',
            'detailed_changes_text' => 'Updated detail',
            'affected_areas_text' => 'Hub',
            'version_decision_text' => 'Patch',
            'validation_text' => 'Reviewed',
        ])->assertRedirect(route('admin.releases.index'));

        $this->assertSame('Updated summary', $release->fresh()->summary);

        $this->actingAs($admin)->delete('/admin/releases/'.$release->id)
            ->assertRedirect(route('admin.releases.index'));

        $this->assertDatabaseMissing('releases', ['id' => $release->id]);
    }
}
