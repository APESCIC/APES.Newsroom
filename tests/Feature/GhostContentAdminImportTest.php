<?php

namespace Tests\Feature;

use App\Jobs\ProcessGhostContentImportJob;
use App\Models\ImportRun;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Import\GhostContentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GhostContentAdminImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): string
    {
        return base_path('tests/fixtures/ghost/content.json');
    }

    public function test_public_cannot_access_importer(): void
    {
        $this->withoutVite();
        $public = User::factory()->create();
        $this->actingAs($public)->get('/admin/imports/ghost-content')->assertForbidden();
    }

    public function test_staff_can_queue_dry_run_upload(): void
    {
        $this->withoutVite();
        Storage::fake();
        Queue::fake();
        Mail::fake();
        $staff = User::factory()->staff()->create();

        $file = new UploadedFile($this->fixture(), 'content.json', 'application/json', null, true);

        $this->actingAs($staff)->post('/admin/imports/ghost-content', [
            'json' => $file,
        ])->assertRedirect();

        Queue::assertPushed(ProcessGhostContentImportJob::class, function (ProcessGhostContentImportJob $job) {
            return $job->dryRun === true && $job->cleanupAfter === false;
        });
        $this->assertDatabaseHas('import_runs', [
            'type' => 'ghost_content',
            'dry_run' => true,
            'actor_id' => $staff->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'import.ghost_content.dry_run',
            'actor_id' => $staff->id,
        ]);
        $this->assertDatabaseCount('posts', 0);
        Mail::assertNothingSent();
    }

    public function test_rejects_non_ghost_json_before_queue(): void
    {
        $this->withoutVite();
        Storage::fake();
        Queue::fake();
        $staff = User::factory()->staff()->create();

        $path = storage_path('app/not-ghost.json');
        file_put_contents($path, json_encode(['hello' => 'world']));
        $file = new UploadedFile($path, 'not-ghost.json', 'application/json', null, true);

        $this->actingAs($staff)->post('/admin/imports/ghost-content', [
            'json' => $file,
        ])->assertSessionHasErrors('json');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('import_runs', 0);
        @unlink($path);
    }

    public function test_confirm_persists_without_duplicates_and_cleans_up(): void
    {
        $this->withoutVite();
        Mail::fake();
        $staff = User::factory()->staff()->create();

        $dir = storage_path('app/imports/ghost-content/test-run');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $jsonPath = $dir.'/content.json';
        copy($this->fixture(), $jsonPath);

        $dryRun = ImportRun::create([
            'type' => 'ghost_content',
            'status' => 'completed',
            'dry_run' => true,
            'source_path' => $jsonPath,
            'source_checksum' => hash_file('sha256', $jsonPath),
            'actor_id' => $staff->id,
            'report' => ['posts' => ['seen' => 2]],
            'finished_at' => now(),
        ]);

        Queue::fake();

        $this->actingAs($staff)->post("/admin/imports/ghost-content/{$dryRun->id}/confirm")
            ->assertRedirect();

        Queue::assertPushed(ProcessGhostContentImportJob::class, function (ProcessGhostContentImportJob $job) {
            return $job->dryRun === false && $job->cleanupAfter === true;
        });
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'import.ghost_content.import',
            'actor_id' => $staff->id,
        ]);

        $persist = ImportRun::query()->where('dry_run', false)->latest('id')->firstOrFail();
        (new ProcessGhostContentImportJob($persist->id, false, true))->handle(app(GhostContentImporter::class));

        $this->assertSame('completed', $persist->fresh()->status);
        $this->assertSame(2, Post::query()->count());
        $this->assertDatabaseHas('tags', ['ghost_id' => 'ghost-tag-1']);
        $this->assertFalse(is_file($jsonPath));
        $this->assertFalse(is_dir($dir));
        Mail::assertNothingSent();

        // Idempotent re-import via importer directly (files already cleaned).
        $again = storage_path('app/imports/ghost-content/again/content.json');
        mkdir(dirname($again), 0777, true);
        copy($this->fixture(), $again);
        app(GhostContentImporter::class)->import($again, null, false, $staff);
        $this->assertSame(2, Post::query()->count());
        $this->assertSame(1, Tag::query()->where('ghost_id', 'ghost-tag-1')->count());
        @unlink($again);
        @rmdir(dirname($again));
    }

    public function test_dry_run_job_writes_nothing(): void
    {
        Mail::fake();
        $staff = User::factory()->staff()->create();
        $dir = storage_path('app/imports/ghost-content/dry-only');
        mkdir($dir, 0777, true);
        $jsonPath = $dir.'/content.json';
        copy($this->fixture(), $jsonPath);

        $run = ImportRun::create([
            'type' => 'ghost_content',
            'status' => 'queued',
            'dry_run' => true,
            'source_path' => $jsonPath,
            'source_checksum' => hash_file('sha256', $jsonPath),
            'actor_id' => $staff->id,
        ]);

        (new ProcessGhostContentImportJob($run->id, true, false))->handle(app(GhostContentImporter::class));

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('tags', 0);
        $this->assertTrue(is_file($jsonPath));
        Mail::assertNothingSent();

        @unlink($jsonPath);
        @rmdir($dir);
    }
}
