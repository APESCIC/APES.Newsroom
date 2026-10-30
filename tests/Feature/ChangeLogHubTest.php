<?php

namespace Tests\Feature;

use App\Models\Release;
use App\Models\User;
use Database\Seeders\ReleaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Former admin release URLs answer like any unknown admin URL: the panel
     * middleware still sends guests to login and refuses staff first.
     *
     * @return array<string, array{?string, int}>
     */
    public static function everyRole(): array
    {
        return [
            'guest' => [null, 302],
            'staff' => ['staff', 403],
            'admin' => ['admin', 404],
            'super admin' => ['superAdmin', 404],
        ];
    }

    #[DataProvider('everyRole')]
    public function test_admin_releases_urls_are_gone_for_every_role(?string $role, int $expectedStatus): void
    {
        $release = Release::factory()->published()->current()->create([
            'version' => 'v1.0.0',
            'slug' => 'release-v100',
            'summary' => 'Original summary',
        ]);

        if ($role !== null) {
            $this->actingAs(User::factory()->{$role}()->create());
        }

        $this->get('/admin/no-such-page')->assertStatus($expectedStatus);

        foreach (['/admin/releases', '/admin/releases/new', "/admin/releases/{$release->id}/edit"] as $url) {
            $this->get($url)->assertStatus($expectedStatus);
        }

        $writes = [
            $this->post('/admin/releases', ['version' => 'v9.9.9', 'summary' => 'Injected']),
            $this->put("/admin/releases/{$release->id}", ['summary' => 'Changed']),
            $this->delete("/admin/releases/{$release->id}"),
        ];

        foreach ($writes as $response) {
            $this->assertFalse($response->isSuccessful());
        }

        $this->assertDatabaseCount('releases', 1);
        $this->assertSame('Original summary', $release->fresh()->summary);
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
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v164',
            'version' => 'v1.6.4',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v165',
            'version' => 'v1.6.5',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v170',
            'version' => 'v1.7.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v171',
            'version' => 'v1.7.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v180',
            'version' => 'v1.8.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v181',
            'version' => 'v1.8.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v190',
            'version' => 'v1.9.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1100',
            'version' => 'v1.10.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-11-0',
            'version' => 'v1.11.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-12-0',
            'version' => 'v1.12.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-13-0',
            'version' => 'v1.13.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-13-1',
            'version' => 'v1.13.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-14-0',
            'version' => 'v1.14.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-15-0',
            'version' => 'v1.15.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-15-1',
            'version' => 'v1.15.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-16-0',
            'version' => 'v1.16.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-16-1',
            'version' => 'v1.16.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-17-0',
            'version' => 'v1.17.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-18-0',
            'version' => 'v1.18.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-19-0',
            'version' => 'v1.19.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-19-1',
            'version' => 'v1.19.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-20-0',
            'version' => 'v1.20.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-21-0',
            'version' => 'v1.21.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-22-0',
            'version' => 'v1.22.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-23-0',
            'version' => 'v1.23.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-24-0',
            'version' => 'v1.24.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-25-0',
            'version' => 'v1.25.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-25-1',
            'version' => 'v1.25.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-25-2',
            'version' => 'v1.25.2',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-25-3',
            'version' => 'v1.25.3',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-25-4',
            'version' => 'v1.25.4',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-26-0',
            'version' => 'v1.26.0',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-26-1',
            'version' => 'v1.26.1',
            'channel' => 'beta',
            'is_current' => false,
            'is_published' => true,
        ]);
        $this->assertDatabaseHas('releases', [
            'slug' => 'release-v1-27-0',
            'version' => 'v1.27.0',
            'channel' => 'beta',
            'is_current' => true,
            'is_published' => true,
        ]);
        $this->assertDatabaseCount('releases', 81);

        $this->get('/change-log-hub')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('releases', 81)
                ->where('current.version', 'v1.27.0')
                ->where('current.channel', 'beta')
                ->where('current.channel_label', 'Beta'));
    }
}
