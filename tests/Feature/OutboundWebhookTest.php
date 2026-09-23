<?php

namespace Tests\Feature;

use App\Enums\Channel;
use App\Enums\PostStatus;
use App\Jobs\DeliverWebhookJob;
use App\Models\Post;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Membership\MembershipService;
use App\Services\Publishing\EditorialPostWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutboundWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_staff_can_create_webhook_endpoint(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->post(route('staff.webhooks.store'), [
                'name' => 'CI sink',
                'url' => 'https://example.com/hooks/newsroom',
                'events' => ['post.published', 'member.created'],
                'is_active' => true,
            ])
            ->assertRedirect(route('staff.webhooks.index'));

        $endpoint = WebhookEndpoint::query()->firstOrFail();
        $this->assertStringStartsWith('whsec_', $endpoint->secret);
        $this->assertTrue($endpoint->listensFor('post.published'));
    }

    public function test_publish_queues_signed_delivery_without_blocking(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $endpoint = WebhookEndpoint::query()->create([
            'name' => 'Sink',
            'url' => 'https://example.com/hook',
            'secret' => 'whsec_testsecret',
            'events' => ['post.published'],
            'is_active' => true,
        ]);

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

        Queue::assertPushed(DeliverWebhookJob::class);
        $this->assertDatabaseHas('webhook_deliveries', [
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'post.published',
            'status' => WebhookDelivery::STATUS_PENDING,
        ]);
    }

    public function test_delivery_job_sends_hmac_signature(): void
    {
        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $endpoint = WebhookEndpoint::query()->create([
            'name' => 'Sink',
            'url' => 'https://example.com/hook',
            'secret' => 'whsec_testsecret',
            'events' => ['member.created'],
            'is_active' => true,
        ]);

        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'member.created',
            'delivery_uuid' => '11111111-1111-1111-1111-111111111111',
            'payload' => [
                'event' => 'member.created',
                'data' => ['user_id' => 1],
            ],
            'status' => WebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
        ]);

        (new DeliverWebhookJob($delivery->id))->handle();

        Http::assertSent(function ($request) use ($endpoint) {
            $body = $request->body();
            $expected = 'sha256='.hash_hmac('sha256', $body, $endpoint->secret);

            return $request->url() === 'https://example.com/hook'
                && $request->hasHeader('X-Newsroom-Signature', $expected)
                && $request->hasHeader('X-Newsroom-Event', 'member.created');
        });

        $this->assertSame(WebhookDelivery::STATUS_DELIVERED, $delivery->fresh()->status);
    }

    public function test_member_created_dispatches_webhook(): void
    {
        Queue::fake();
        WebhookEndpoint::query()->create([
            'name' => 'Sink',
            'url' => 'https://example.com/hook',
            'secret' => 'whsec_testsecret',
            'events' => ['member.created'],
            'is_active' => true,
        ]);

        $user = User::factory()->create();
        app(MembershipService::class)->provisionFreeMember($user);

        Queue::assertPushed(DeliverWebhookJob::class);
    }

    public function test_failed_dispatch_does_not_break_publish(): void
    {
        $admin = User::factory()->admin()->create();
        // Force dispatcher path with no endpoints — should still succeed.
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::Draft,
            'channel' => Channel::ApesCic,
            'content' => [
                'time' => now()->getTimestampMs(),
                'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Body']]],
                'version' => '2.29.0',
            ],
        ]);

        $published = app(EditorialPostWriter::class)->publish($admin, $post);
        $this->assertSame(PostStatus::Published, $published->status);
    }
}
