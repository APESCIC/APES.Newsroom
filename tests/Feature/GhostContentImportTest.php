<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Import\GhostContentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GhostContentImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): string
    {
        return base_path('tests/fixtures/ghost/content.json');
    }

    public function test_dry_run_reconciles_counts_without_writing(): void
    {
        Mail::fake();

        Artisan::call('ghost:import-content', [
            'json' => $this->fixture(),
            '--dry-run' => true,
        ]);

        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('tags', 0);
        $output = Artisan::output();
        $this->assertStringContainsString('"seen": 2', $output);
        $this->assertStringContainsString('Dry-run complete', $output);
        Mail::assertNothingSent();
    }

    public function test_import_is_idempotent_and_sends_no_mail(): void
    {
        Mail::fake();
        User::factory()->admin()->create();

        Artisan::call('ghost:import-content', [
            'json' => $this->fixture(),
            '--force' => true,
        ]);

        $this->assertDatabaseHas('posts', ['ghost_id' => 'ghost-post-1', 'slug' => 'welcome-to-apes']);
        $this->assertDatabaseHas('tags', ['ghost_id' => 'ghost-tag-1', 'slug' => 'announcements']);
        $this->assertSame(2, Post::query()->count());

        Artisan::call('ghost:import-content', [
            'json' => $this->fixture(),
            '--force' => true,
        ]);

        $this->assertSame(2, Post::query()->count());
        $this->assertSame(1, Tag::query()->where('ghost_id', 'ghost-tag-1')->count());
        $this->assertDatabaseHas('redirects', ['from_path' => '/old-welcome/']);
        Mail::assertNothingSent();
    }

    public function test_unsupported_html_is_flagged_for_review(): void
    {
        User::factory()->admin()->create();

        Artisan::call('ghost:import-content', [
            'json' => $this->fixture(),
            '--force' => true,
        ]);

        $post = Post::query()->where('ghost_id', 'ghost-post-2')->first();
        $this->assertNotNull($post);
        $this->assertTrue((bool) $post->needs_import_review);
        $types = collect($post->content['blocks'])->pluck('type');
        $this->assertTrue($types->contains('paragraph') || $types->contains('legacy'));
    }

    public function test_sequential_imports_in_same_process_do_not_break_mail_guard(): void
    {
        // Simulates queue:work handling dry-run then confirm without a fresh process (#137).
        User::factory()->admin()->create();
        $importer = app(GhostContentImporter::class);
        $mailerBefore = config('mail.default');

        $importer->import($this->fixture(), null, true);
        $importer->import($this->fixture(), null, false);

        $this->assertSame(2, Post::query()->count());
        $this->assertSame($mailerBefore, config('mail.default'));
    }
}
