<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Models\User;
use Database\Seeders\ReleaseSeeder;
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
        // RefreshDatabase also runs the baseline seed migration; clear so each
        // feature test can control published/current release fixtures.
        Release::query()->delete();
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

    public function test_seed_migration_upserts_baseline_releases(): void
    {
        (new ReleaseSeeder)->run();

        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v111',
            'version' => 'v1.1.1',
            'channel' => 'beta',
            'version_type' => 'patch beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v100',
            'channel' => 'beta',
            'version_type' => 'major beta',
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v110',
            'channel' => 'beta',
            'version_type' => 'minor beta',
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v112',
            'version' => 'v1.1.2',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v113',
            'version' => 'v1.1.3',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v114',
            'version' => 'v1.1.4',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v115',
            'version' => 'v1.1.5',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v116',
            'version' => 'v1.1.6',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v117',
            'version' => 'v1.1.7',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v118',
            'version' => 'v1.1.8',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v119',
            'version' => 'v1.1.9',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1110',
            'version' => 'v1.1.10',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v120',
            'version' => 'v1.2.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v121',
            'version' => 'v1.2.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v122',
            'version' => 'v1.2.2',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v123',
            'version' => 'v1.2.3',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v124',
            'version' => 'v1.2.4',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v125',
            'version' => 'v1.2.5',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v130',
            'version' => 'v1.3.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v131',
            'version' => 'v1.3.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v132',
            'version' => 'v1.3.2',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v133',
            'version' => 'v1.3.3',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v134',
            'version' => 'v1.3.4',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v135',
            'version' => 'v1.3.5',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v136',
            'version' => 'v1.3.6',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v140',
            'version' => 'v1.4.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v141',
            'version' => 'v1.4.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v142',
            'version' => 'v1.4.2',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v143',
            'version' => 'v1.4.3',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v144',
            'version' => 'v1.4.4',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v145',
            'version' => 'v1.4.5',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v146',
            'version' => 'v1.4.6',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v147',
            'version' => 'v1.4.7',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v150',
            'version' => 'v1.5.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v151',
            'version' => 'v1.5.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v152',
            'version' => 'v1.5.2',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v153',
            'version' => 'v1.5.3',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v154',
            'version' => 'v1.5.4',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v155',
            'version' => 'v1.5.5',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v156',
            'version' => 'v1.5.6',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v157',
            'version' => 'v1.5.7',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v158',
            'version' => 'v1.5.8',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v159',
            'version' => 'v1.5.9',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v160',
            'version' => 'v1.6.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v161',
            'version' => 'v1.6.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v162',
            'version' => 'v1.6.2',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v163',
            'version' => 'v1.6.3',
            'channel' => 'beta',
            'is_current' => true,
            'is_published' => true,
        ]);
        $this->assertDatabaseCount('releases', 47);

        $this->get('/change-log-hub')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('releases', 47)
                ->where('current.version', 'v1.6.3')
                ->where('current.channel', 'beta')
                ->where('current.channel_label', 'Beta'));
    }

    public function test_admin_create_form_defaults_to_beta_while_in_beta(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/releases/new')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Releases/Edit')
                ->where('releasesInBeta', true)
                ->where('release', null));
    }
}
