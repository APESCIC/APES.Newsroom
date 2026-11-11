<?php

namespace Tests\Unit\Membership;

use App\Models\Offer;
use App\Services\Integrations\IntegrationSecrets;
use App\Services\Membership\StripeApiBillingClient;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeApiBillingClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, params: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $requests = &$this->requests;
        ApiRequestor::setHttpClient(new class($requests) implements ClientInterface
        {
            public function __construct(private array &$requests) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];
                $id = str_contains($absUrl, 'promotion_codes') ? 'promo_123' : 'coupon_123';

                return [json_encode(['id' => $id]), 200, []];
            }
        });
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_offer_promotion_codes_use_the_promotion_coupon_shape(): void
    {
        $offer = (new Offer)->forceFill([
            'name' => 'Spring offer',
            'code' => 'SPRING10',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'is_active' => true,
            'max_redemptions' => 50,
        ]);

        $client = new StripeApiBillingClient(new StripeClient('sk_test_example'), app(IntegrationSecrets::class));

        $this->assertSame(
            ['coupon_id' => 'coupon_123', 'promotion_code_id' => 'promo_123'],
            $client->createOfferPromotion($offer),
        );

        $promotion = collect($this->requests)->first(fn (array $request) => str_ends_with($request['url'], '/v1/promotion_codes'));
        $this->assertNotNull($promotion);
        $this->assertSame(['type' => 'coupon', 'coupon' => 'coupon_123'], $promotion['params']['promotion']);
        $this->assertArrayNotHasKey('coupon', $promotion['params']);
        $this->assertSame('SPRING10', $promotion['params']['code']);
        $this->assertSame(50, $promotion['params']['max_redemptions']);
    }
}
