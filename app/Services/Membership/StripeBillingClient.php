<?php

namespace App\Services\Membership;

use App\Models\MembershipPlan;
use App\Models\User;

interface StripeBillingClient
{
    /**
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(
        User $user,
        MembershipPlan $plan,
        string $successUrl,
        string $cancelUrl,
        ?string $promotionCodeId = null,
    ): array;

    /**
     * @return array{url: string}
     */
    public function createBillingPortalSession(User $user, string $returnUrl): array;

    /**
     * @return array<string, mixed>
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): array;
}
