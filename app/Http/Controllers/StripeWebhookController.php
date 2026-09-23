<?php

namespace App\Http\Controllers;

use App\Services\Membership\MembershipBillingService;
use App\Services\Membership\StripeBillingClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeBillingClient $stripe,
        private readonly MembershipBillingService $billing,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $signature);
            $this->billing->handleWebhookEvent($event);
        } catch (\InvalidArgumentException $e) {
            Log::warning('stripe.webhook.rejected', ['message' => $e->getMessage()]);

            return response('Invalid payload', 400);
        }

        return response('ok', 200);
    }
}
