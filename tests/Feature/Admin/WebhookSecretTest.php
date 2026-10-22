<?php

namespace Tests\Feature\Admin;

use App\Jobs\DeliverWebhookJob;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_secrets_are_encrypted_at_rest(): void
    {
        $endpoint = $this->endpoint('whsec_plaintextvalue');

        $stored = (string) DB::table('webhook_endpoints')->where('id', $endpoint->id)->value('secret');

        $this->assertStringNotContainsString('whsec_plaintextvalue', $stored);
        $this->assertSame('whsec_plaintextvalue', Crypt::decryptString($stored));
        $this->assertSame('whsec_plaintextvalue', $endpoint->fresh()->secret);
        $this->assertArrayNotHasKey('secret', $endpoint->fresh()->toArray());
    }

    public function test_full_secret_is_shown_once_after_create(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->post(route('admin.webhooks.store'), ['name' => 'Partner', 'url' => 'https://hooks.example.com', 'events' => ['post.published']])
            ->assertRedirect(route('admin.webhooks.index'));

        $secret = WebhookEndpoint::query()->sole()->secret;

        $this->actingAs($admin)->get(route('admin.webhooks.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('revealedSecret.secret', $secret)
                ->where('endpoints.0.secret_hint', 'whsec_…'.substr($secret, -4)));

        $response = $this->actingAs($admin)->get(route('admin.webhooks.index'));
        $response->assertInertia(fn (Assert $page) => $page->where('revealedSecret', null));
        $this->assertStringNotContainsString($secret, $response->getContent());
    }

    public function test_rotated_secret_is_shown_once_and_saving_without_rotation_reveals_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $endpoint = $this->endpoint('whsec_original');
        $payload = ['name' => 'Partner', 'url' => 'https://hooks.example.com', 'events' => ['post.published'], 'is_active' => true];

        $this->actingAs($admin)->withRecentAuthentication()->patch(route('admin.webhooks.update', $endpoint), $payload);
        $this->actingAs($admin)->get(route('admin.webhooks.index'))->assertInertia(fn (Assert $page) => $page->where('revealedSecret', null));

        $this->actingAs($admin)->withRecentAuthentication()->patch(route('admin.webhooks.update', $endpoint), [...$payload, 'rotate_secret' => true]);
        $rotated = $endpoint->fresh()->secret;
        $this->assertNotSame('whsec_original', $rotated);

        $this->actingAs($admin)->get(route('admin.webhooks.index'))->assertInertia(fn (Assert $page) => $page->where('revealedSecret.secret', $rotated));
    }

    public function test_deliveries_are_signed_with_the_decrypted_secret(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response('', 200)]);
        $endpoint = $this->endpoint('whsec_signing');
        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'post.published',
            'delivery_uuid' => (string) Str::uuid(),
            'payload' => ['post_id' => 1],
            'status' => 'pending',
        ]);

        (new DeliverWebhookJob($delivery->id))->handle();

        Http::assertSent(function ($request) {
            return $request->header('X-Newsroom-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 'whsec_signing');
        });
    }

    public function test_existing_plaintext_secrets_are_encrypted_by_an_idempotent_migration(): void
    {
        $id = DB::table('webhook_endpoints')->insertGetId([
            'name' => 'Legacy',
            'url' => 'https://hooks.example.com',
            'secret' => 'whsec_legacyplaintext',
            'events' => json_encode(['post.published']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_10_22_120100_encrypt_existing_webhook_secrets.php');
        $migration->up();
        $once = (string) DB::table('webhook_endpoints')->where('id', $id)->value('secret');
        $migration->up();
        $twice = (string) DB::table('webhook_endpoints')->where('id', $id)->value('secret');

        $this->assertSame($once, $twice);
        $this->assertSame('whsec_legacyplaintext', WebhookEndpoint::query()->findOrFail($id)->secret);
    }

    public function test_staff_cannot_manage_webhooks(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->withRecentAuthentication()->get(route('admin.webhooks.index'))->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()
            ->post(route('admin.webhooks.store'), ['name' => 'x', 'url' => 'https://hooks.example.com', 'events' => ['post.published']])
            ->assertForbidden();

        $this->assertSame(0, WebhookEndpoint::query()->count());
    }

    private function endpoint(string $secret): WebhookEndpoint
    {
        return WebhookEndpoint::query()->create([
            'name' => 'Partner',
            'url' => 'https://hooks.example.com/in',
            'secret' => $secret,
            'events' => ['post.published'],
            'is_active' => true,
        ]);
    }
}
