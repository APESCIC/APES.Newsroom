<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPostTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_create_a_draft(): void
    {
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Test Article',
            'slug' => 'test-article',
            'excerpt' => 'An excerpt',
            'channel' => 'apes_cic',
            'content' => [
                'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body text']]],
            ],
            'tags' => ['News', 'Update'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('posts', ['slug' => 'test-article', 'status' => PostStatus::Draft->value]);
        $this->assertDatabaseHas('tags', ['slug' => 'news']);
    }

    public function test_public_user_cannot_access_staff_posts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/staff/posts')->assertForbidden();
    }

    public function test_admin_can_publish_post(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create(['author_id' => $admin->id]);

        $this->actingAs($admin)->post("/staff/posts/{$post->id}/publish")->assertRedirect();

        $this->assertSame(PostStatus::Published, $post->fresh()->status);
    }

    public function test_staff_can_submit_for_review(): void
    {
        $staff = User::factory()->staff()->create();
        $post = Post::factory()->create(['author_id' => $staff->id]);

        $this->actingAs($staff)->post("/staff/posts/{$post->id}/submit")->assertRedirect();

        $this->assertSame(PostStatus::InReview, $post->fresh()->status);
    }

    public function test_admin_can_reject_with_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::InReview,
        ]);

        $this->actingAs($admin)->post("/staff/posts/{$post->id}/reject", [
            'review_notes' => 'Needs stronger lead',
        ])->assertRedirect();

        $post->refresh();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('Needs stronger lead', $post->review_notes);
    }

    public function test_staff_cannot_publish(): void
    {
        $staff = User::factory()->staff()->create();
        $post = Post::factory()->create(['author_id' => $staff->id]);

        $this->actingAs($staff)->post("/staff/posts/{$post->id}/publish")->assertForbidden();
    }

    public function test_slug_change_on_published_post_creates_redirect(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'slug' => 'old-slug',
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);

        $this->actingAs($admin)->patch("/staff/posts/{$post->id}", [
            'title' => $post->title,
            'slug' => 'new-slug',
            'excerpt' => $post->excerpt,
            'channel' => $post->channel->value,
            'content' => $post->content,
            'expected_updated_at' => $post->updated_at?->toIso8601String(),
        ])->assertRedirect();

        $this->assertDatabaseHas('redirects', [
            'from_path' => '/articles/old-slug',
            'to_path' => '/articles/new-slug',
            'status_code' => 301,
        ]);
        $this->assertInstanceOf(Redirect::class, Redirect::query()->where('from_path', '/articles/old-slug')->first());
    }

    public function test_concurrent_edit_reports_conflict(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create(['author_id' => $admin->id]);

        $this->actingAs($admin)->patch("/staff/posts/{$post->id}", [
            'title' => 'Updated title',
            'slug' => $post->slug,
            'channel' => $post->channel->value,
            'content' => $post->content,
            'expected_updated_at' => '2000-01-01T00:00:00+00:00',
        ])->assertSessionHasErrors('conflict');
    }

    public function test_admin_can_unpublish(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);

        $this->actingAs($admin)->post("/staff/posts/{$post->id}/unpublish")->assertRedirect();

        $this->assertSame(PostStatus::Unpublished, $post->fresh()->status);
    }

    public function test_admin_review_queue(): void
    {
        $admin = User::factory()->admin()->create();
        Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::InReview,
            'title' => 'Awaiting review',
        ]);

        $this->actingAs($admin)
            ->get('/staff/posts/review')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Staff/Posts/Review')
                ->has('posts', 1));
    }

    public function test_xss_payload_rejected_on_update(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create(['author_id' => $admin->id]);

        $this->actingAs($admin)->patch("/staff/posts/{$post->id}", [
            'title' => $post->title,
            'slug' => $post->slug,
            'channel' => $post->channel->value,
            'content' => [
                'blocks' => [['type' => 'paragraph', 'data' => ['text' => '<script>alert(1)</script>']]],
            ],
            'expected_updated_at' => $post->updated_at?->toIso8601String(),
        ])->assertSessionHasErrors('content');
    }

    public function test_staff_can_create_draft_with_gallery_video_and_audio(): void
    {
        $staff = User::factory()->staff()->create();

        $response = $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Media Article',
            'slug' => 'media-article',
            'excerpt' => 'With rich media',
            'channel' => 'apes_cic',
            'content' => [
                'blocks' => [
                    [
                        'type' => 'gallery',
                        'data' => [
                            'items' => [
                                [
                                    'url' => 'https://cdn.example.com/a.jpg',
                                    'alt' => 'A photo',
                                ],
                            ],
                        ],
                    ],
                    [
                        'type' => 'video',
                        'data' => [
                            'url' => 'https://cdn.example.com/clip.mp4',
                        ],
                    ],
                    [
                        'type' => 'audio',
                        'data' => [
                            'url' => 'https://cdn.example.com/track.mp3',
                        ],
                    ],
                ],
            ],
        ]);

        $response->assertRedirect();
        $post = Post::query()->where('slug', 'media-article')->first();
        $this->assertNotNull($post);
        $this->assertSame('gallery', $post->content['blocks'][0]['type']);
        $this->assertSame('video', $post->content['blocks'][1]['type']);
        $this->assertSame('audio', $post->content['blocks'][2]['type']);
    }

    public function test_malformed_gallery_is_rejected_on_create(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post('/staff/posts', [
            'title' => 'Bad Gallery',
            'slug' => 'bad-gallery',
            'channel' => 'apes_cic',
            'content' => [
                'blocks' => [[
                    'type' => 'gallery',
                    'data' => [
                        'items' => [
                            ['url' => 'https://cdn.example.com/a.jpg', 'alt' => ''],
                        ],
                    ],
                ]],
            ],
        ])->assertSessionHasErrors('content');
    }
}
