<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Import\GhostContentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GhostContentImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): string
    {
        return base_path('tests/fixtures/ghost/content.json');
    }

    public function test_hero_images_come_from_the_media_archive_or_keep_their_ghost_url_for_repair(): void
    {
        Mail::fake();
        User::factory()->admin()->create();
        $work = storage_path('framework/testing/ghost-hero-'.uniqid());
        File::ensureDirectoryExists($work.'/media/2024/01');
        file_put_contents($work.'/media/2024/01/archive-hero.jpg', 'jpeg-bytes');

        $export = json_decode((string) file_get_contents($this->fixture()), true);
        $export['db'][0]['data']['posts'][0]['feature_image'] = '__GHOST_URL__/content/images/size/w1200/2024/01/archive-hero.jpg';
        $export['db'][0]['data']['posts'][1]['feature_image'] = '__GHOST_URL__/content/images/2024/01/not-in-archive.jpg';
        file_put_contents($work.'/content.json', json_encode($export));
        $copied = storage_path('app/public/imports/archive-hero.jpg');

        try {
            app(GhostContentImporter::class)->import($work.'/content.json', $work.'/media', false);

            $slugs = collect($export['db'][0]['data']['posts'])->pluck('slug');
            $this->assertSame(
                rtrim((string) config('app.url'), '/').'/storage/imports/archive-hero.jpg',
                Post::query()->where('slug', $slugs[0])->value('hero_image'),
            );
            $this->assertFileExists($copied);
            $this->assertSame(
                '__GHOST_URL__/content/images/2024/01/not-in-archive.jpg',
                Post::query()->where('slug', $slugs[1])->value('hero_image'),
            );
        } finally {
            File::deleteDirectory($work);
            File::delete($copied);
        }
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
