<?php

namespace Tests\Feature\Api;

use App\Enums\Channel;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function bearerFor(User $user): string
    {
        return ApiToken::issue($user, 'test')['token'];
    }

    public function test_missing_token_is_unauthorized(): void
    {
        $this->getJson('/api/admin/v1/posts')->assertUnauthorized();
    }

    public function test_staff_can_create_and_update_post_via_admin_api(): void
    {
        $staff = User::factory()->staff()->create();
        $token = $this->bearerFor($staff);

        $create = $this->withToken($token)
            ->postJson('/api/admin/v1/posts', [
                'title' => 'API Draft',
                'channel' => Channel::ApesCic->value,
                'content' => [
                    'time' => now()->getTimestampMs(),
                    'blocks' => [
                        ['type' => 'paragraph', 'data' => ['text' => 'Hello from API']],
                    ],
                    'version' => '2.29.0',
                ],
                'tags' => ['News'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PostStatus::Draft->value)
            ->assertJsonPath('data.title', 'API Draft');

        $id = $create->json('data.id');

        $this->withToken($token)
            ->patchJson('/api/admin/v1/posts/'.$id, [
                'title' => 'API Draft Updated',
                'content' => [
                    'time' => now()->getTimestampMs(),
                    'blocks' => [
                        ['type' => 'paragraph', 'data' => ['text' => 'Updated body']],
                    ],
                    'version' => '2.29.0',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'API Draft Updated');

        $this->assertDatabaseHas('posts', [
            'id' => $id,
            'title' => 'API Draft Updated',
            'author_id' => $staff->id,
        ]);
    }

    public function test_staff_cannot_publish_but_admin_can(): void
    {
        $staff = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $staff->id,
            'status' => PostStatus::Draft,
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Ready']],
                ],
                'version' => '2.29.0',
            ],
        ]);

        $this->withToken($this->bearerFor($staff))
            ->postJson('/api/admin/v1/posts/'.$post->id.'/publish')
            ->assertForbidden();

        $this->withToken($this->bearerFor($admin))
            ->postJson('/api/admin/v1/posts/'.$post->id.'/publish')
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Published->value);

        $this->assertSame(PostStatus::Published, $post->fresh()->status);

        $this->withToken($this->bearerFor($admin))
            ->postJson('/api/admin/v1/posts/'.$post->id.'/unpublish')
            ->assertOk()
            ->assertJsonPath('data.status', PostStatus::Unpublished->value);
    }

    public function test_invalid_editor_blocks_are_rejected(): void
    {
        $staff = User::factory()->staff()->create();

        $this->withToken($this->bearerFor($staff))
            ->postJson('/api/admin/v1/posts', [
                'title' => 'Bad blocks',
                'channel' => Channel::ApesCic->value,
                'content' => [
                    'blocks' => [
                        ['type' => 'not-a-real-block', 'data' => []],
                    ],
                ],
            ])
            ->assertStatus(422);
    }

    public function test_public_user_token_is_forbidden(): void
    {
        $public = User::factory()->create(['role' => Role::Public]);
        $token = $this->bearerFor($public);

        $this->withToken($token)
            ->getJson('/api/admin/v1/posts')
            ->assertForbidden();
    }
}
