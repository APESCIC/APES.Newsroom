<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Services\Publishing\EditorialPostWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlackEditorialNotifyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_publish_notifies_slack_when_configured(): void
    {
        config([
            'services.slack.notifications.bot_user_oauth_token' => 'xoxb-test',
            'services.slack.notifications.channel' => '#editorial',
        ]);

        Http::fake([
            'slack.com/*' => Http::response(['ok' => true], 200),
        ]);

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::Draft,
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]],
                'version' => '2.29.0',
            ],
        ]);

        app(EditorialPostWriter::class)->publish($admin, $post);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && $request['channel'] === '#editorial'
            && str_contains((string) $request['text'], 'Published'));
    }

    public function test_submit_for_review_notifies_slack(): void
    {
        config([
            'services.slack.notifications.bot_user_oauth_token' => 'xoxb-test',
            'services.slack.notifications.channel' => '#editorial',
        ]);
        Http::fake(['slack.com/*' => Http::response(['ok' => true], 200)]);

        $staff = User::factory()->staff()->create();
        $post = Post::factory()->create([
            'author_id' => $staff->id,
            'status' => PostStatus::Draft,
        ]);

        $this->actingAs($staff)
            ->post(route('staff.posts.submit', $post))
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'In review'));
    }

    public function test_missing_slack_config_is_noop(): void
    {
        config([
            'services.slack.notifications.bot_user_oauth_token' => null,
            'services.slack.notifications.channel' => null,
        ]);
        Http::fake();

        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::Draft,
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]],
                'version' => '2.29.0',
            ],
        ]);

        app(EditorialPostWriter::class)->publish($admin, $post);
        Http::assertNothingSent();
        $this->assertSame(PostStatus::Published, $post->fresh()->status);
    }
}
