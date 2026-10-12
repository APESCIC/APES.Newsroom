<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivilegedChangeAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_an_api_token_is_audited_without_the_token(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'staffer@example.com']);

        $this->artisan('newsroom:issue-admin-api-token', ['email' => 'staffer@example.com', '--name' => 'ci'])
            ->assertSuccessful();

        $token = ApiToken::query()->sole();
        $log = AuditLog::query()->where('action', 'api_token.issued')->sole();

        $this->assertSame($token->id, $log->subject_id);
        $this->assertSame(['name' => 'ci', 'owner_id' => $staff->id, 'source' => 'cli'], $log->payload);
        $this->assertStringNotContainsString($token->token_hash, (string) json_encode($log->getAttributes()));
        $this->assertStringNotContainsString('nr_admin_', (string) json_encode($log->getAttributes()));
    }

    public function test_revoking_api_tokens_audits_each_token(): void
    {
        $staff = User::factory()->staff()->create();
        $first = ApiToken::issue($staff, 'one')['model'];
        $second = ApiToken::issue($staff, 'two')['model'];

        $this->artisan('newsroom:revoke-admin-api-token', ['--user' => $staff->email, '--force' => true])
            ->assertSuccessful();

        $logs = AuditLog::query()->where('action', 'api_token.revoked')->orderBy('id')->get();
        $this->assertSame([$first->id, $second->id], $logs->pluck('subject_id')->all());
        $this->assertSame('one', $logs->first()->payload['name']);
        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_webhook_create_update_rotate_and_delete_are_audited_without_secrets(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post(route('staff.webhooks.store'), [
            'name' => 'Partner',
            'url' => 'https://hooks.example.com/in?key=query-credential',
            'events' => ['post.published'],
        ])->assertRedirect();

        $webhook = WebhookEndpoint::query()->sole();
        $originalSecret = $webhook->secret;

        $this->actingAs($staff)->patch(route('staff.webhooks.update', $webhook), [
            'name' => 'Partner renamed',
            'url' => 'https://hooks.example.com/in?key=query-credential',
            'events' => ['post.published'],
            'is_active' => true,
            'rotate_secret' => true,
        ])->assertRedirect();

        $rotatedSecret = $webhook->fresh()->secret;

        $this->actingAs($staff)->delete(route('staff.webhooks.destroy', $webhook))->assertRedirect();

        $logs = AuditLog::query()->where('action', 'like', 'webhook.%')->orderBy('id')->get();

        $this->assertSame(
            ['webhook.created', 'webhook.updated', 'webhook.secret_rotated', 'webhook.deleted'],
            $logs->pluck('action')->all(),
        );

        foreach ($logs as $log) {
            $this->assertSame($staff->id, $log->actor_id);
            $this->assertSame($webhook->id, $log->subject_id);
            $this->assertSame('hooks.example.com', $log->payload['host']);

            $serialised = (string) json_encode($log->getAttributes());
            $this->assertStringNotContainsString($originalSecret, $serialised);
            $this->assertStringNotContainsString($rotatedSecret, $serialised);
            $this->assertStringNotContainsString('query-credential', $serialised);
        }
    }
}
