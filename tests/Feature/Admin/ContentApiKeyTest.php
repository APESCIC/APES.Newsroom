<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\ContentApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContentApiKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['newsroom.content_api.key' => '']);
    }

    public function test_admin_creates_a_named_key_shown_once_and_stored_hashed(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->post('/admin/content-api-keys', ['name' => 'Partner site'])
            ->assertRedirect('/admin/content-api-keys');

        $key = ContentApiKey::query()->sole();
        $plain = null;

        $this->actingAs($admin)->get('/admin/content-api-keys')
            ->assertInertia(function (Assert $page) use (&$plain, $key) {
                $page->where('created.id', $key->id)->where('created.key', function (string $value) use (&$plain) {
                    $plain = $value;

                    return str_starts_with($value, ContentApiKey::PREFIX);
                });
            });

        $this->assertSame(hash('sha256', (string) $plain), $key->key_hash);
        $this->assertSame(substr((string) $plain, 0, 15), $key->prefix);
        $this->assertDatabaseMissing('content_api_keys', ['key_hash' => $plain]);

        $second = $this->actingAs($admin)->get('/admin/content-api-keys');
        $second->assertInertia(fn (Assert $page) => $page->where('created', null)->where('keys.0.name', 'Partner site'));
        $this->assertStringNotContainsString((string) $plain, $second->getContent());
        $this->assertStringNotContainsString($key->key_hash, $second->getContent());

        $this->assertSame(['name' => 'Partner site', 'prefix' => $key->prefix], AuditLog::query()->where('action', 'content_api_key.created')->sole()->payload);
    }

    public function test_multiple_active_keys_work_and_revoked_keys_stop(): void
    {
        $admin = User::factory()->admin()->create();
        $first = ContentApiKey::issue('First', $admin);
        $second = ContentApiKey::issue('Second', $admin);

        $this->getJson('/api/content/v1/posts', ['X-Newsroom-Content-Key' => $first['key']])->assertOk();
        $this->getJson('/api/content/v1/posts', ['Authorization' => "Newsroom-Key {$second['key']}"])->assertOk();
        $this->assertNotNull($first['model']->fresh()->last_used_at);

        $this->actingAs($admin)->withRecentAuthentication()
            ->delete("/admin/content-api-keys/{$first['model']->id}", ['reason' => 'Partner contract ended'])
            ->assertRedirect('/admin/content-api-keys');

        $this->assertNotNull($first['model']->fresh()->revoked_at);
        $this->assertSame('Partner contract ended', AuditLog::query()->where('action', 'content_api_key.revoked')->sole()->payload['reason']);

        $this->getJson('/api/content/v1/posts', ['X-Newsroom-Content-Key' => $first['key']])->assertUnauthorized();
        $this->getJson('/api/content/v1/posts', ['X-Newsroom-Content-Key' => $second['key']])->assertOk();
    }

    public function test_environment_key_still_works_as_a_fallback(): void
    {
        config(['newsroom.content_api.key' => 'env-fallback-key']);
        ContentApiKey::issue('Named', null);

        $this->getJson('/api/content/v1/posts', ['X-Newsroom-Content-Key' => 'env-fallback-key'])->assertOk();
        $this->getJson('/api/content/v1/posts', ['X-Newsroom-Content-Key' => 'wrong'])->assertUnauthorized();
    }

    public function test_api_is_unconfigured_without_any_active_key(): void
    {
        $this->getJson('/api/content/v1/posts', ['X-Newsroom-Content-Key' => 'anything'])->assertStatus(503);

        $key = ContentApiKey::issue('Only', null);
        $this->getJson('/api/content/v1/posts')->assertUnauthorized();

        $key['model']->forceFill(['revoked_at' => now()])->save();
        $this->getJson('/api/content/v1/posts', ['X-Newsroom-Content-Key' => $key['key']])->assertStatus(503);
    }

    public function test_managing_keys_needs_an_admin_and_recent_authentication(): void
    {
        $key = ContentApiKey::issue('Existing', null)['model'];

        $this->actingAs(User::factory()->admin()->create())
            ->delete("/admin/content-api-keys/{$key->id}", ['reason' => 'No re-auth'])
            ->assertRedirect(route('account.confirm-identity'));

        $this->actingAs(User::factory()->staff()->create())->withRecentAuthentication()
            ->post('/admin/content-api-keys', ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertSame(1, ContentApiKey::query()->count());
        $this->assertNull($key->fresh()->revoked_at);
    }
}
