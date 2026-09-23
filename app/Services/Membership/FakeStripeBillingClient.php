<?php

namespace App\Services\Membership;

use App\Models\MembershipPlan;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Str;

class FakeStripeBillingClient implements StripeBillingClient
{
    /** @var list<array<string, mixed>> */
    public array $checkouts = [];

    /** @var list<array<string, mixed>> */
    public array $portals = [];

    /** @var list<array<string, mixed>> */
    public array $offers = [];

    public function createCheckoutSession(
        User $user,
        MembershipPlan $plan,
        string $successUrl,
        string $cancelUrl,
        ?string $promotionCodeId = null,
        array $metadata = [],
    ): array {
        $id = 'cs_test_'.Str::random(16);
        $mergedMetadata = array_merge([
            'user_id' => (string) $user->id,
            'membership_plan_id' => (string) $plan->id,
        ], $metadata);
        $session = [
            'id' => $id,
            'url' => $successUrl.(str_contains($successUrl, '?') ? '&' : '?').'session_id='.$id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'promotion_code_id' => $promotionCodeId,
            'metadata' => $mergedMetadata,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ];
        $this->checkouts[] = $session;

        return ['id' => $id, 'url' => $session['url']];
    }

    public function createBillingPortalSession(User $user, string $returnUrl): array
    {
        $url = $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?').'portal=1';
        $this->portals[] = ['user_id' => $user->id, 'url' => $url];

        return ['url' => $url];
    }

    public function createOfferPromotion(Offer $offer): array
    {
        $couponId = 'coupon_test_'.Str::lower($offer->code ?: Str::random(6));
        $promoId = 'promo_test_'.Str::lower($offer->code ?: Str::random(6));
        $this->offers[] = [
            'code' => $offer->code,
            'coupon_id' => $couponId,
            'promotion_code_id' => $promoId,
        ];

        return [
            'coupon_id' => $couponId,
            'promotion_code_id' => $promoId,
        ];
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader): array
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded) || ! isset($decoded['id'], $decoded['type'])) {
            throw new \InvalidArgumentException('Invalid fake Stripe webhook payload.');
        }

        if ($signatureHeader === '' || $signatureHeader === 'bad') {
            throw new \InvalidArgumentException('Invalid Stripe signature.');
        }

        return $decoded;
    }
}
