<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GhostPageBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function fixture(): string
    {
        return base_path('tests/fixtures/ghost/content.json');
    }

    public function test_import_routes_ghost_pages_to_page_model(): void
    {
        User::factory()->admin()->create();

        Artisan::call('ghost:import-content', [
            'json' => $this->fixture(),
            '--force' => true,
        ]);

        $this->assertSame(2, Post::query()->count());
        $this->assertDatabaseMissing('posts', ['ghost_id' => 'ghost-page-1']);
        $this->assertDatabaseHas('pages', [
            'ghost_id' => 'ghost-page-1',
            'slug' => 'about-apes',
            'status' => PostStatus::Published->value,
        ]);
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/articles/about-apes',
            'to_path' => '/pages/about-apes',
        ]);

        $this->get('/pages/about-apes')->assertOk();
    }

    public function test_backfill_converts_misclassified_post_idempotently(): void
    {
        $admin = User::factory()->admin()->create();

        $misclassified = Post::factory()->published()->create([
            'author_id' => $admin->id,
            'ghost_id' => 'ghost-page-1',
            'slug' => 'about-apes',
            'title' => 'About APES',
        ]);

        Artisan::call('ghost:backfill-pages', [
            'json' => $this->fixture(),
            '--force' => true,
        ]);

        $this->assertSoftDeleted($misclassified);
        $this->assertSame(1, Page::query()->where('ghost_id', 'ghost-page-1')->count());
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/articles/about-apes',
            'to_path' => '/pages/about-apes',
        ]);

        Artisan::call('ghost:backfill-pages', [
            'json' => $this->fixture(),
            '--force' => true,
        ]);

        $this->assertSame(1, Page::query()->where('ghost_id', 'ghost-page-1')->count());
        $this->assertSame(1, Redirect::query()->where('from_path', '/articles/about-apes')->count());
    }
}
