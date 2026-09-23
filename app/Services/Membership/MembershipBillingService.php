<?php

namespace App\Services\Membership;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Offer;
use App\Models\OfferRedemption;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MembershipBillingService
{
    public function __construct(
        private readonly StripeBillingClient $stripe,
        private readonly MembershipService $memberships,
        private readonly OfferService $offers,
        private readonly OutboundWebhookDispatcher $webhooks,
    ) {}

    public function startCheckout(User $user, MembershipPlan $plan, ?string $offerCode = null): string
    {
        if (! $plan->is_active) {
            throw new \InvalidArgumentException('That membership plan is not available.');
        }

        $membership = $this->memberships->ensureMembership($user);

        if ($membership->isPaying()) {
            throw new \InvalidArgumentException('You already have an active paid membership.');
        }

        $promotionCodeId = null;
        $metadata = [];

        if (filled($offerCode)) {
            $offer = $this->offers->resolveValidOffer($offerCode);
            $promotionCodeId = $offer->stripe_promotion_code_id;
            $metadata['offer_id'] = (string) $offer->id;
        }

        $session = $this->stripe->createCheckoutSession(
            $user,
            $plan,
            route('account.membership.success'),
            route('account.show'),
            $promotionCodeId,
            $metadata,
        );

        return $session['url'];
    }

    public function startBillingPortal(User $user): string
    {
        $membership = $this->memberships->ensureMembership($user);

        if (blank($membership->stripe_customer_id)) {
            throw new \InvalidArgumentException('No Stripe customer is linked to this membership yet.');
        }

        return $this->stripe->createBillingPortalSession($user, route('account.show'))['url'];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function handleWebhookEvent(array $event): void
    {
        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($eventId === '' || $type === '') {
            throw new \InvalidArgumentException('Webhook event is missing id or type.');
        }

        DB::transaction(function () use ($event, $eventId, $type): void {
            $existing = StripeWebhookEvent::query()->where('event_id', $eventId)->lockForUpdate()->first();

            if ($existing?->processed_at) {
                return;
            }

            $row = $existing ?? StripeWebhookEvent::query()->create([
                'event_id' => $eventId,
                'type' => $type,
            ]);

            match ($type) {
                'checkout.session.completed' => $this->onCheckoutCompleted($event['data']['object'] ?? []),
                'customer.subscription.updated' => $this->onSubscriptionUpdated($event['data']['object'] ?? []),
                'customer.subscription.deleted' => $this->onSubscriptionDeleted($event['data']['object'] ?? []),
                'invoice.payment_failed' => $this->onInvoicePaymentFailed($event['data']['object'] ?? []),
                default => null,
            };

            $row->forceFill([
                'type' => $type,
                'processed_at' => now(),
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function onCheckoutCompleted(array $session): void
    {
        $userId = (int) ($session['client_reference_id']
            ?? $session['metadata']['user_id']
            ?? 0);

        if ($userId < 1) {
            return;
        }

        $user = User::query()->find($userId);

        if (! $user) {
            return;
        }

        $membership = $this->memberships->ensureMembership($user);
        $planId = (int) ($session['metadata']['membership_plan_id'] ?? 0);
        $plan = $planId > 0 ? MembershipPlan::query()->find($planId) : null;

        $membership->fill([
            'status' => MembershipStatus::Active,
            'membership_plan_id' => $plan?->id,
            'interval' => $plan?->interval,
            'stripe_customer_id' => $session['customer'] ?? $membership->stripe_customer_id,
            'stripe_subscription_id' => $session['subscription'] ?? $membership->stripe_subscription_id,
        ]);
        $membership->save();

        $this->emitSubscriptionUpdated($membership);

        $offerId = (int) ($session['metadata']['offer_id'] ?? 0);

        if ($offerId > 0) {
            $offer = Offer::query()->lockForUpdate()->find($offerId);

            if ($offer) {
                OfferRedemption::query()->firstOrCreate(
                    [
                        'offer_id' => $offer->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'stripe_checkout_session_id' => $session['id'] ?? null,
                    ],
                );

                $offer->update([
                    'redemption_count' => $offer->redemptions()->count(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function onSubscriptionUpdated(array $subscription): void
    {
        $membership = $this->findMembershipForSubscription($subscription);

        if (! $membership) {
            return;
        }

        $status = match ((string) ($subscription['status'] ?? '')) {
            'active', 'trialing' => MembershipStatus::Active,
            'past_due', 'unpaid' => MembershipStatus::PastDue,
            'canceled', 'incomplete_expired' => MembershipStatus::Canceled,
            default => $membership->status,
        };

        $periodEnd = isset($subscription['current_period_end'])
            ? Carbon::createFromTimestamp((int) $subscription['current_period_end'])
            : $membership->current_period_end;

        $planId = (int) ($subscription['metadata']['membership_plan_id'] ?? 0);
        $plan = $planId > 0 ? MembershipPlan::query()->find($planId) : $membership->plan;

        $membership->fill([
            'status' => $status,
            'membership_plan_id' => $plan?->id ?? $membership->membership_plan_id,
            'interval' => $plan?->interval ?? $membership->interval,
            'stripe_customer_id' => $subscription['customer'] ?? $membership->stripe_customer_id,
            'stripe_subscription_id' => $subscription['id'] ?? $membership->stripe_subscription_id,
            'current_period_end' => $periodEnd,
        ]);
        $membership->save();
        $this->emitSubscriptionUpdated($membership);
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function onSubscriptionDeleted(array $subscription): void
    {
        $membership = $this->findMembershipForSubscription($subscription);

        if (! $membership) {
            return;
        }

        $membership->fill([
            'status' => MembershipStatus::Canceled,
            'stripe_subscription_id' => $subscription['id'] ?? $membership->stripe_subscription_id,
            'current_period_end' => isset($subscription['current_period_end'])
                ? Carbon::createFromTimestamp((int) $subscription['current_period_end'])
                : now(),
        ]);
        $membership->save();
        $this->emitSubscriptionUpdated($membership);
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function onInvoicePaymentFailed(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? null;

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return;
        }

        $membership = Membership::query()->where('stripe_subscription_id', $subscriptionId)->first();

        if (! $membership) {
            return;
        }

        $membership->fill(['status' => MembershipStatus::PastDue])->save();
        $this->emitSubscriptionUpdated($membership);
    }

    private function emitSubscriptionUpdated(Membership $membership): void
    {
        $this->webhooks->dispatch(OutboundWebhookDispatcher::EVENT_SUBSCRIPTION_UPDATED, [
            'user_id' => $membership->user_id,
            'membership_id' => $membership->id,
            'status' => $membership->status->value,
            'interval' => $membership->interval,
            'plan_id' => $membership->membership_plan_id,
            'current_period_end' => $membership->current_period_end?->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $subscription
     */
    private function findMembershipForSubscription(array $subscription): ?Membership
    {
        $subscriptionId = (string) ($subscription['id'] ?? '');

        if ($subscriptionId !== '') {
            $bySub = Membership::query()->where('stripe_subscription_id', $subscriptionId)->first();

            if ($bySub) {
                return $bySub;
            }
        }

        $userId = (int) ($subscription['metadata']['user_id'] ?? 0);

        if ($userId > 0) {
            return Membership::query()->where('user_id', $userId)->first();
        }

        $customerId = (string) ($subscription['customer'] ?? '');

        if ($customerId !== '') {
            return Membership::query()->where('stripe_customer_id', $customerId)->first();
        }

        return null;
    }
}
