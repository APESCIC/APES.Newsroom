<?php

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ModerationQueuePaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_index_paginates_and_filters_profiles(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (range(1, 16) as $i) {
            $user = User::factory()->create(['name' => "Pending User {$i}"]);
            Profile::query()->create([
                'user_id' => $user->id,
                'display_name' => $i === 1 ? 'Unique Glass Name' : "Profile {$i}",
                'bio' => 'Pending bio',
                'moderation_status' => ModerationStatus::Pending,
                'public_opt_in' => true,
            ]);
        }

        $this->actingAs($admin)
            ->get('/admin/moderation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Moderation/Index')
                ->where('counts.profiles', 16)
                ->has('profiles.data', 15)
                ->where('profiles.meta.per_page', 15)
                ->where('profiles.meta.last_page', 2)
                ->where('filters.q', ''));

        $this->actingAs($admin)
            ->get('/admin/moderation?q=Unique+Glass')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.profiles', 1)
                ->has('profiles.data', 1)
                ->where('profiles.data.0.display_name', 'Unique Glass Name')
                ->where('filters.q', 'Unique Glass'));
    }

    public function test_moderation_actions_unchanged_after_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $profile = Profile::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Pending',
            'moderation_status' => ModerationStatus::Pending,
            'public_opt_in' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/moderation/profiles/'.$profile->id, ['status' => 'approved'])
            ->assertRedirect();

        $this->assertSame(ModerationStatus::Approved, $profile->fresh()->moderation_status);
    }
}
