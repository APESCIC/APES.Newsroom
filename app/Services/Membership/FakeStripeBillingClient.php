<?php

namespace App\Services\Membership;

use App\Models\MembershipPlan;
use App\Models\User;
use Illuminate\Support\Str;

class FakeStripeBillingClient implements StripeBillingClient
{
    /** @var list<array<string, mixed>> */
    public array $checkouts = [];

    /** @var list<array<string, mixed>> */
    public array $portals = [];

    public function createCheckoutSession(
        User $user,
        MembershipPlan $plan,
        string $successUrl,
        string $cancelUrl,
        ?string $promotionCodeId = null,
    ): array {
        $id = 'cs_test_'.Str::random(16);
        $session = [
            'id' => $id,
            'url' => $successUrl.(str_contains($successUrl, '?') ? '&' : '?').'session_id='.$id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'promotion_code_id' => $promotionCodeId,
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
