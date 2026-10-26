<?php

namespace Tests\Feature;

use App\Models\IntegrationSecret;
use App\Models\User;
use App\Services\Integrations\IntegrationSecrets;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class IntegrationSecretsTest extends TestCase
{
    use RefreshDatabase;

    public function test_environment_value_is_used_when_nothing_is_stored(): void
    {
        config(['services.mailgun.secret' => 'env-mailgun-key-1234']);

        $secrets = app(IntegrationSecrets::class);

        $this->assertSame('env-mailgun-key-1234', $secrets->get(IntegrationDefinitions::MAILGUN, 'api_key'));
        $this->assertSame(IntegrationSecrets::SOURCE_ENVIRONMENT, $secrets->source(IntegrationDefinitions::MAILGUN, 'api_key'));
    }

    public function test_unset_key_resolves_to_null(): void
    {
        config(['services.mailgun.secret' => null]);

        $secrets = app(IntegrationSecrets::class);

        $this->assertNull($secrets->get(IntegrationDefinitions::MAILGUN, 'api_key'));
        $this->assertNull($secrets->source(IntegrationDefinitions::MAILGUN, 'api_key'));
        $this->assertFalse($secrets->configured(IntegrationDefinitions::MAILGUN, 'api_key'));
    }

    public function test_stored_value_wins_over_environment_and_is_encrypted_at_rest(): void
    {
        config(['services.mailgun.secret' => 'env-mailgun-key-1234']);
        $admin = User::factory()->superAdmin()->create();
        $secrets = app(IntegrationSecrets::class);

        $secrets->get(IntegrationDefinitions::MAILGUN, 'api_key');
        $result = $secrets->set(IntegrationDefinitions::MAILGUN, 'api_key', 'db-mailgun-key-abcd', $admin);

        $this->assertFalse($result['replaced']);
        $this->assertSame('db-mailgun-key-abcd', $secrets->get(IntegrationDefinitions::MAILGUN, 'api_key'));
        $this->assertSame(IntegrationSecrets::SOURCE_DATABASE, $secrets->source(IntegrationDefinitions::MAILGUN, 'api_key'));

        $raw = (string) DB::table('integration_secrets')->value('value');
        $this->assertStringNotContainsString('db-mailgun-key-abcd', $raw);
        $this->assertSame('db-mailgun-key-abcd', Crypt::decryptString($raw));

        $model = IntegrationSecret::query()->sole();
        $this->assertSame('abcd', $model->last_four);
        $this->assertSame($admin->id, $model->set_by);
        $this->assertArrayNotHasKey('value', $model->toArray());
        $this->assertStringNotContainsString('db-mailgun-key-abcd', $model->toJson());
    }

    public function test_cache_holds_ciphertext_only(): void
    {
        $secrets = app(IntegrationSecrets::class);
        $secrets->set(IntegrationDefinitions::STRIPE, 'secret', 'sk_test_cachedvalue9999', null);
        $secrets->get(IntegrationDefinitions::STRIPE, 'secret');

        $cached = Cache::get(IntegrationSecrets::CACHE_KEY);

        $this->assertIsArray($cached);
        $this->assertArrayHasKey('stripe.secret', $cached);
        $this->assertStringNotContainsString('sk_test_cachedvalue9999', serialize($cached));
    }

    public function test_replacing_marks_rotation_and_takes_effect_immediately(): void
    {
        $secrets = app(IntegrationSecrets::class);
        $secrets->set(IntegrationDefinitions::SLACK, 'bot_token', 'xoxb-first-token-1111', null);
        $this->assertSame('xoxb-first-token-1111', $secrets->get(IntegrationDefinitions::SLACK, 'bot_token'));

        $result = $secrets->set(IntegrationDefinitions::SLACK, 'bot_token', 'xoxb-second-token-2222', null);

        $this->assertTrue($result['replaced']);
        $this->assertNotNull($result['secret']->rotated_at);
        $this->assertSame('xoxb-second-token-2222', $secrets->get(IntegrationDefinitions::SLACK, 'bot_token'));
    }

    public function test_removing_falls_back_to_environment(): void
    {
        config(['newsroom.unsplash.access_key' => 'env-unsplash-key']);
        $secrets = app(IntegrationSecrets::class);
        $secrets->set(IntegrationDefinitions::UNSPLASH, 'access_key', 'db-unsplash-key-5555', null);

        $removed = $secrets->remove(IntegrationDefinitions::UNSPLASH, 'access_key');

        $this->assertNotNull($removed);
        $this->assertSame('env-unsplash-key', $secrets->get(IntegrationDefinitions::UNSPLASH, 'access_key'));
        $this->assertSame(0, IntegrationSecret::query()->count());
    }

    public function test_undecryptable_value_falls_back_to_environment(): void
    {
        config(['services.mailgun.secret' => 'env-mailgun-key-1234']);
        DB::table('integration_secrets')->insert([
            'provider' => 'mailgun',
            'key' => 'api_key',
            'value' => 'not-a-valid-ciphertext',
            'last_four' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('env-mailgun-key-1234', app(IntegrationSecrets::class)->get(IntegrationDefinitions::MAILGUN, 'api_key'));
    }

    public function test_unknown_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(IntegrationSecrets::class)->set('mailgun', 'not_a_key', 'value', null);
    }
}
