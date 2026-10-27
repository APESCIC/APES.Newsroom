<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffPostsIndexFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_index_supports_title_search_and_channel_filter(): void
    {
        $admin = User::factory()->admin()->create();
        $author = User::factory()->staff()->create(['name' => 'River Author']);

        Post::factory()->create([
            'author_id' => $author->id,
            'title' => 'Glass Studio launch',
            'status' => PostStatus::Published,
            'channel' => Channel::ApesCic,
        ]);
        Post::factory()->create([
            'author_id' => $author->id,
            'title' => 'Shelter rescue day',
            'status' => PostStatus::Published,
            'channel' => Channel::ApesShelterRescue,
        ]);

        $this->actingAs($admin)
            ->get('/staff/posts?q=Glass')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/Posts/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.title', 'Glass Studio launch')
                ->where('filters.q', 'Glass'));

        $this->actingAs($admin)
            ->get('/staff/posts?channel=apes_shelter_rescue')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 1)
                ->where('posts.data.0.channel', 'apes_shelter_rescue')
                ->where('filterChannel', 'apes_shelter_rescue'));

        $this->actingAs($admin)
            ->get('/staff/posts?q=River&status=published')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 2)
                ->where('filterStatus', 'published'));
    }

    public function test_index_is_paginated_and_keeps_filters_in_page_links(): void
    {
        $admin = User::factory()->admin()->create();
        Post::factory()->count(23)->create(['author_id' => $admin->id, 'status' => PostStatus::Draft]);

        $this->actingAs($admin)
            ->get('/staff/posts?status=draft')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('posts.data', 20)
                ->where('posts.total', 23)
                ->where('posts.last_page', 2)
                ->where('posts.next_page_url', fn (string $url) => str_contains($url, 'status=draft') && str_contains($url, 'page=2')));

        $this->actingAs($admin)
            ->get('/staff/posts?status=draft&page=2')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('posts.data', 3));
    }
}
