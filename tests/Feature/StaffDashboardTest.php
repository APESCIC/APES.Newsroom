<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_sent_to_sign_in_and_public_users_are_forbidden(): void
    {
        $this->get('/staff')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/staff')->assertForbidden();
    }

    public function test_staff_and_trailing_slash_render_the_dashboard_inside_the_panel(): void
    {
        $staff = User::factory()->staff()->create();

        foreach (['/staff', '/staff/'] as $path) {
            $this->actingAs($staff)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Staff/Dashboard'));
        }
    }

    public function test_staff_see_counts_and_lists_for_their_own_posts_only(): void
    {
        $staff = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();

        Post::factory()->count(2)->create(['author_id' => $staff->id, 'status' => PostStatus::Draft]);
        Post::factory()->create(['author_id' => $staff->id, 'status' => PostStatus::InReview]);
        Post::factory()->create(['author_id' => $staff->id, 'status' => PostStatus::Scheduled, 'scheduled_for' => now()->addDay()]);
        Post::factory()->published()->create(['author_id' => $staff->id, 'published_at' => now()->subDays(2)]);
        Post::factory()->published()->create(['author_id' => $staff->id, 'published_at' => now()->subDays(20)]);
        Post::factory()->count(3)->create(['author_id' => $other->id, 'status' => PostStatus::Draft, 'title' => 'Not mine']);

        $this->actingAs($staff)
            ->get('/staff')
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts', ['draft' => 2, 'in_review' => 1, 'scheduled' => 1, 'published_7d' => 1])
                ->where('attention.review', null)
                ->has('attention.drafts', 2)
                ->has('attention.scheduled', 1)
                ->has('recent', 6)
                ->where('recent', fn ($rows) => collect($rows)->every(fn (array $row) => $row['title'] !== 'Not mine'))
                ->where('canReview', false));
    }

    public function test_staff_never_receive_membership_revenue(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/staff')
            ->assertInertia(fn (Assert $page) => $page
                ->has('insights.web')
                ->has('insights.newsletters')
                ->where('insights.subscriptions', null));
    }

    public function test_admins_see_the_review_queue_all_authors_and_revenue(): void
    {
        $admin = User::factory()->admin()->create();
        $author = User::factory()->staff()->create();
        Post::factory()->count(7)->create(['author_id' => $author->id, 'status' => PostStatus::InReview]);
        Post::factory()->create(['author_id' => $author->id, 'status' => PostStatus::Draft]);

        $this->actingAs($admin)
            ->get('/staff')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canReview', true)
                ->where('counts.in_review', 7)
                ->where('counts.draft', 1)
                ->has('attention.review', 5)
                ->has('attention.drafts', 0)
                ->has('insights.subscriptions.mrr_pence'));
    }

    public function test_dashboard_query_count_does_not_grow_with_posts(): void
    {
        $admin = User::factory()->admin()->create();
        $authors = User::factory()->staff()->count(3)->create();
        foreach ($authors as $author) {
            Post::factory()->create(['author_id' => $author->id, 'status' => PostStatus::InReview]);
            Post::factory()->create(['author_id' => $author->id, 'status' => PostStatus::Scheduled, 'scheduled_for' => now()->addDay()]);
        }
        Post::factory()->create(['author_id' => $admin->id, 'status' => PostStatus::Draft]);

        $this->actingAs($admin)->get('/staff')->assertOk();

        foreach ($authors as $author) {
            Post::factory()->count(6)->create(['author_id' => $author->id, 'status' => PostStatus::InReview]);
            Post::factory()->count(4)->create(['author_id' => $author->id, 'status' => PostStatus::Draft]);
        }

        $small = $this->countQueries(fn () => $this->actingAs($admin)->get('/staff')->assertOk());
        User::factory()->staff()->count(5)->create()->each(
            fn (User $author) => Post::factory()->count(3)->create(['author_id' => $author->id, 'status' => PostStatus::Scheduled, 'scheduled_for' => now()->addDay()]),
        );
        $large = $this->countQueries(fn () => $this->actingAs($admin)->get('/staff')->assertOk());

        $this->assertSame($small, $large, 'The staff dashboard should not issue more queries as posts and authors grow.');
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
