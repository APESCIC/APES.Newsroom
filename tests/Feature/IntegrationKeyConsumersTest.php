<?php

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Services\Integrations\AiAssistService;
use App\Services\Integrations\IntegrationSecrets;
use App\Services\Integrations\SlackEditorialNotifier;
use App\Services\Integrations\UnsplashService;
use App\Services\Mail\DnsTxtResolver;
use App\Services\Mail\MailgunSenderCheck;
use App\Services\Membership\StripeApiBillingClient;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Stripe\StripeClient;
use Tests\TestCase;

class IntegrationKeyConsumersTest extends TestCase
{
    use RefreshDatabase;

    public function test_mailgun_check_uses_the_stored_key_endpoint_and_domain(): void
    {
        config([
            'services.mailgun.secret' => 'env-mailgun-key-0000',
            'services.mailgun.domain' => 'env.example.org',
            'services.mailgun.endpoint' => 'api.eu.mailgun.net',
            'mailing.sender_domains' => ['apesnews.org.uk'],
        ]);
        $this->app->instance(DnsTxtResolver::class, new class extends DnsTxtResolver
        {
            public function txt(string $host): array
            {
                return [];
            }
        });
        $this->secrets()->set(IntegrationDefinitions::MAILGUN, 'api_key', 'db-mailgun-key-1111', null);
        $this->secrets()->set(IntegrationDefinitions::MAILGUN, 'endpoint', 'api.mailgun.net', null);
        $this->secrets()->set(IntegrationDefinitions::MAILGUN, 'domain', 'mg.apesnews.org.uk', null);
        Http::fake(['api.mailgun.net/v4/domains/*' => Http::response(['domain' => ['state' => 'active'], 'sending_dns_records' => []])]);

        app(MailgunSenderCheck::class)->check((new Newsletter)->forceFill(['sender_email' => 'news@apesnews.org.uk']));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.mailgun.net/v4/domains/mg.apesnews.org.uk'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('api:db-mailgun-key-1111'));
    }

    public function test_unsplash_falls_back_to_environment_then_uses_replacement_on_next_resolve(): void
    {
        config(['newsroom.unsplash.access_key' => 'env-unsplash-key']);
        Http::fake(['api.unsplash.com/*' => Http::response(['results' => []])]);

        app(UnsplashService::class)->search('apes');
        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Client-ID env-unsplash-key');

        $this->secrets()->set(IntegrationDefinitions::UNSPLASH, 'access_key', 'db-unsplash-key-2222', null);

        app(UnsplashService::class)->search('apes');
        Http::assertSent(fn (Request $request) => $request->header('Authorization')[0] === 'Client-ID db-unsplash-key-2222');
    }

    public function test_openai_and_slack_read_the_store(): void
    {
        config(['newsroom.ai_assist.enabled' => true, 'newsroom.ai_assist.api_key' => null]);
        $this->assertFalse(app(AiAssistService::class)->enabled());

        $this->secrets()->set(IntegrationDefinitions::OPENAI, 'api_key', 'sk-db-openai-3333', null);
        $this->assertTrue(app(AiAssistService::class)->enabled());

        config(['services.slack.notifications.bot_user_oauth_token' => null, 'services.slack.notifications.channel' => null]);
        $this->assertFalse(app(SlackEditorialNotifier::class)->enabled());

        $this->secrets()->set(IntegrationDefinitions::SLACK, 'bot_token', 'xoxb-db-token-4444', null);
        $this->secrets()->set(IntegrationDefinitions::SLACK, 'default_channel', '#newsroom', null);
        $this->assertTrue(app(SlackEditorialNotifier::class)->enabled());
    }

    public function test_stripe_webhook_secret_is_read_from_the_store(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_envsecret']);
        $this->secrets()->set(IntegrationDefinitions::STRIPE, 'webhook_secret', 'whsec_dbsecret5555', null);
        $client = new StripeApiBillingClient(new StripeClient('sk_test_unused'), $this->secrets());

        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'ping']);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_dbsecret5555');

        $this->assertSame('evt_1', $client->constructWebhookEvent($payload, $signature)['id']);

        $envSignature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_envsecret');
        $this->expectException(InvalidArgumentException::class);
        $client->constructWebhookEvent($payload, $envSignature);
    }

    private function secrets(): IntegrationSecrets
    {
        return app(IntegrationSecrets::class);
    }
}
