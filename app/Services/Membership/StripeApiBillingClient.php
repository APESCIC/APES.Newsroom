<?php

namespace App\Services\Membership;

use App\Models\MembershipPlan;
use App\Models\Offer;
use App\Models\User;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeApiBillingClient implements StripeBillingClient
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function createCheckoutSession(
        User $user,
        MembershipPlan $plan,
        string $successUrl,
        string $cancelUrl,
        ?string $promotionCodeId = null,
        array $metadata = [],
    ): array {
        if (blank($plan->stripe_price_id)) {
            throw new \RuntimeException('Membership plan is missing a Stripe price id.');
        }

        $mergedMetadata = array_merge([
            'user_id' => (string) $user->id,
            'membership_plan_id' => (string) $plan->id,
        ], $metadata);

        $params = [
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'price' => $plan->stripe_price_id,
                'quantity' => 1,
            ]],
            'client_reference_id' => (string) $user->id,
            'metadata' => $mergedMetadata,
            'subscription_data' => [
                'metadata' => $mergedMetadata,
            ],
        ];

        if ($user->membership?->stripe_customer_id) {
            $params['customer'] = $user->membership->stripe_customer_id;
        } else {
            $params['customer_email'] = $user->email;
        }

        if ($promotionCodeId) {
            $params['discounts'] = [['promotion_code' => $promotionCodeId]];
        }

        /** @var Session $session */
        $session = $this->stripe->checkout->sessions->create($params);

        return [
            'id' => (string) $session->id,
            'url' => (string) $session->url,
        ];
    }

    public function createBillingPortalSession(User $user, string $returnUrl): array
    {
        $customerId = $user->membership?->stripe_customer_id;

        if (blank($customerId)) {
            throw new \RuntimeException('Membership has no Stripe customer.');
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return ['url' => (string) $session->url];
    }

    public function createOfferPromotion(Offer $offer): array
    {
        $couponParams = [
            'name' => $offer->name,
            'duration' => 'once',
        ];

        if ($offer->discount_type === 'percent') {
            $couponParams['percent_off'] = $offer->discount_value;
        } else {
            $couponParams['amount_off'] = $offer->discount_value;
            $couponParams['currency'] = 'gbp';
        }

        $coupon = $this->stripe->coupons->create($couponParams);

        $promoParams = [
            'coupon' => $coupon->id,
            'code' => $offer->code,
            'active' => $offer->is_active,
        ];

        if ($offer->max_redemptions !== null) {
            $promoParams['max_redemptions'] = $offer->max_redemptions;
        }

        if ($offer->ends_at) {
            $promoParams['expires_at'] = $offer->ends_at->getTimestamp();
        }

        $promo = $this->stripe->promotionCodes->create($promoParams);

        return [
            'coupon_id' => (string) $coupon->id,
            'promotion_code_id' => (string) $promo->id,
        ];
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): array
    {
        $secret = (string) config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $secret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            throw new \InvalidArgumentException('Invalid Stripe webhook: '.$e->getMessage(), previous: $e);
        }

        return $event->toArray();
    }
}
