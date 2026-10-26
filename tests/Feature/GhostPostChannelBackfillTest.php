<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GhostPostChannelBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): string
    {
        return base_path('tests/fixtures/ghost/content-newsletters.json');
    }

    /**
     * @return array<string, Post>
     */
    private function importedPosts(): array
    {
        $admin = User::factory()->admin()->create();
        $posts = [];
        foreach (['ghost-times-1', 'ghost-portal-1', 'ghost-stream-1', 'ghost-default-1'] as $ghostId) {
            $posts[$ghostId] = Post::factory()->published()->create([
                'author_id' => $admin->id,
                'ghost_id' => $ghostId,
                'channel' => $ghostId === 'ghost-default-1' ? Channel::ApesShelterRescue : Channel::ApesCic,
            ]);
        }

        return $posts;
    }

    public function test_import_files_posts_under_their_ghost_newsletter(): void
    {
        User::factory()->admin()->create();

        Artisan::call('ghost:import-content', ['json' => $this->fixture(), '--force' => true]);

        $this->assertSame(Channel::ApesTimes, Post::query()->where('ghost_id', 'ghost-times-1')->value('channel'));
        $this->assertSame(Channel::MyapesPortal, Post::query()->where('ghost_id', 'ghost-portal-1')->value('channel'));
        $this->assertSame(Channel::Stream4Apes, Post::query()->where('ghost_id', 'ghost-stream-1')->value('channel'));
        $this->assertSame(
            Channel::ApesShelterRescue,
            Post::query()->where('ghost_id', 'ghost-default-1')->value('channel'),
            'the default newsletter keeps the tag and title heuristic',
        );
    }

    public function test_dry_run_lists_changes_without_writing(): void
    {
        $posts = $this->importedPosts();

        $exit = Artisan::call('ghost:backfill-post-channels', ['json' => $this->fixture(), '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('3 post(s) would be reassigned', $output);
        $this->assertStringContainsString('1 not found locally', $output);
        $this->assertStringContainsString('apes_times', $output);
        foreach ($posts as $post) {
            $this->assertSame($post->channel, $post->fresh()->channel);
        }
    }

    public function test_it_reads_the_meta_and_data_export_shape(): void
    {
        $posts = $this->importedPosts();
        $export = json_decode((string) file_get_contents($this->fixture()), true);
        $path = tempnam(sys_get_temp_dir(), 'ghost-export');
        file_put_contents($path, json_encode(['meta' => $export['db'][0]['meta'], 'data' => $export['db'][0]['data']]));

        try {
            Artisan::call('ghost:backfill-post-channels', ['json' => $path, '--force' => true]);
        } finally {
            unlink($path);
        }

        $this->assertSame(Channel::ApesTimes, $posts['ghost-times-1']->fresh()->channel);
        $this->assertSame(Channel::MyapesPortal, $posts['ghost-portal-1']->fresh()->channel);
    }

    public function test_it_rejects_files_without_posts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ghost-export');
        file_put_contents($path, json_encode(['meta' => [], 'data' => ['tags' => []]]));

        try {
            $this->expectExceptionMessage('File is not a Ghost content JSON export.');
            Artisan::call('ghost:backfill-post-channels', ['json' => $path]);
        } finally {
            unlink($path);
        }
    }

    public function test_newsletter_only_skips_title_and_slug_matches(): void
    {
        $posts = $this->importedPosts();

        Artisan::call('ghost:backfill-post-channels', ['json' => $this->fixture(), '--dry-run' => true]);
        $output = Artisan::output();
        $this->assertStringContainsString('ghost newsletter', $output);
        $this->assertStringContainsString('title or slug', $output);

        Artisan::call('ghost:backfill-post-channels', ['json' => $this->fixture(), '--force' => true, '--newsletter-only' => true]);

        $this->assertSame(Channel::ApesTimes, $posts['ghost-times-1']->fresh()->channel);
        $this->assertSame(Channel::MyapesPortal, $posts['ghost-portal-1']->fresh()->channel);
        $this->assertSame(Channel::ApesCic, $posts['ghost-stream-1']->fresh()->channel, 'title-only match is skipped');
    }

    public function test_without_force_it_refuses_to_write(): void
    {
        $posts = $this->importedPosts();

        Artisan::call('ghost:backfill-post-channels', ['json' => $this->fixture()]);

        $this->assertStringContainsString('Refusing to write without --force', Artisan::output());
        $this->assertSame(Channel::ApesCic, $posts['ghost-times-1']->fresh()->channel);
    }

    public function test_force_reassigns_newsletter_posts_only_and_is_idempotent(): void
    {
        Mail::fake();
        $posts = $this->importedPosts();

        Artisan::call('ghost:backfill-post-channels', ['json' => $this->fixture(), '--force' => true]);

        $this->assertSame(Channel::ApesTimes, $posts['ghost-times-1']->fresh()->channel);
        $this->assertSame(Channel::MyapesPortal, $posts['ghost-portal-1']->fresh()->channel);
        $this->assertSame(Channel::Stream4Apes, $posts['ghost-stream-1']->fresh()->channel);
        $this->assertSame(Channel::ApesShelterRescue, $posts['ghost-default-1']->fresh()->channel);

        Artisan::call('ghost:backfill-post-channels', ['json' => $this->fixture(), '--force' => true]);
        $this->assertStringContainsString('0 post(s) reassigned; 3 already filed correctly', Artisan::output());

        Mail::assertNothingOutgoing();
    }
}
