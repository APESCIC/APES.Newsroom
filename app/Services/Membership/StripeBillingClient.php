<?php

namespace App\Services\Membership;

use App\Models\MembershipPlan;
use App\Models\Offer;
use App\Models\User;

interface StripeBillingClient
{
    /**
     * @param  array<string, string>  $metadata
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(
        User $user,
        MembershipPlan $plan,
        string $successUrl,
        string $cancelUrl,
        ?string $promotionCodeId = null,
        array $metadata = [],
    ): array;

    /**
     * @return array{url: string}
     */
    public function createBillingPortalSession(User $user, string $returnUrl): array;

    /**
     * @return array{coupon_id: string, promotion_code_id: string}
     */
    public function createOfferPromotion(Offer $offer): array;

    /**
     * @return array<string, mixed>
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): array;
}
