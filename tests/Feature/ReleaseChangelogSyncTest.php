<?php

namespace Tests\Feature;

use App\Enums\ReleaseChannel;
use App\Models\Release;
use App\Services\Releases\ReleaseChangelogSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ReleaseChangelogSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Release::query()->delete();

        $this->fixtureDir = storage_path('framework/testing/changelog-'.uniqid('', true));
        File::ensureDirectoryExists($this->fixtureDir);
        config(['newsroom.changelog_path' => $this->fixtureDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureDir);
        parent::tearDown();
    }

    public function test_sync_upserts_releases_and_marks_highest_current(): void
    {
        $this->writeEntry('v1.0.0.json', [
            'version' => 'v1.0.0',
            'summary' => 'First',
            'detailed_changes' => ['Launch'],
            'change_types' => ['added'],
            'topic_tags' => ['public-facing'],
            'released_at' => '2026-08-01',
        ]);
        $this->writeEntry('v1.0.1.json', [
            'version' => 'v1.0.1',
            'summary' => 'Patch',
            'detailed_changes' => ['Fix'],
            'change_types' => ['fixed'],
            'topic_tags' => ['public-facing'],
            'released_at' => '2026-08-02',
            'pr' => 42,
        ]);

        $count = app(ReleaseChangelogSync::class)->sync();

        $this->assertSame(2, $count);
        $this->assertDatabaseCount('releases', 2);
        $this->assertDatabaseHas('releases', [
            'version' => 'v1.0.1',
            'slug' => 'release-v101',
            'is_current' => true,
            'is_published' => true,
            'channel' => 'beta',
            'version_type' => 'patch beta',
        ]);
        $this->assertDatabaseHas('releases', [
            'version' => 'v1.0.0',
            'is_current' => false,
            'version_type' => 'major beta',
        ]);

        $patch = Release::query()->where('version', 'v1.0.1')->firstOrFail();
        $this->assertContains('Pull request: #42', $patch->version_decision);
    }

    public function test_sync_uses_stable_channel_when_not_in_beta(): void
    {
        config(['newsroom.releases_in_beta' => false]);

        $this->writeEntry('v2.0.0.json', [
            'version' => 'v2.0.0',
            'summary' => 'Stable launch',
            'detailed_changes' => ['Out of beta'],
            'change_types' => ['changed'],
            'topic_tags' => ['public-facing'],
        ]);

        app(ReleaseChangelogSync::class)->sync();

        $this->assertDatabaseHas('releases', [
            'version' => 'v2.0.0',
            'channel' => ReleaseChannel::Stable->value,
            'version_type' => 'major stable',
        ]);
    }

    public function test_artisan_sync_command_loads_repo_changelog_files(): void
    {
        config(['newsroom.changelog_path' => null]);

        $exit = Artisan::call('newsroom:sync-releases');
        $this->assertSame(0, $exit);
        $this->assertGreaterThanOrEqual(40, Release::query()->count());

        $current = Release::query()->where('is_current', true)->firstOrFail();
        $this->assertSame('v1.5.6', $current->version);
        $this->assertSame(ReleaseChannel::Beta, $current->channel);
    }

    public function test_check_pr_changelog_passes_with_skip_label(): void
    {
        $exit = Artisan::call('newsroom:check-pr-changelog', [
            '--labels' => 'docs,skip-changelog',
            '--base' => 'HEAD',
        ]);

        $this->assertSame(0, $exit);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeEntry(string $name, array $payload): void
    {
        File::put(
            $this->fixtureDir.'/'.$name,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
    }
}
