<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\Membership\FakeStripeBillingClient;
use App\Services\Membership\MembershipBillingService;
use App\Services\Membership\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipStripeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_plans_are_seeded_monthly_and_yearly(): void
    {
        $this->assertDatabaseHas('membership_plans', ['slug' => 'monthly', 'interval' => 'month']);
        $this->assertDatabaseHas('membership_plans', ['slug' => 'yearly', 'interval' => 'year']);
    }

    public function test_checkout_redirects_to_fake_stripe_session(): void
    {
        $user = User::factory()->create();
        $fake = app(FakeStripeBillingClient::class);

        $response = $this->actingAs($user)
            ->post(route('account.membership.checkout'), ['plan' => 'monthly']);

        $response->assertRedirect();
        $this->assertCount(1, $fake->checkouts);
        $this->assertSame($user->id, $fake->checkouts[0]['user_id']);
    }

    public function test_checkout_session_completed_marks_membership_active(): void
    {
        $user = User::factory()->create();
        $plan = MembershipPlan::query()->where('slug', 'monthly')->firstOrFail();
        app(MembershipService::class)->ensureMembership($user);

        $payload = [
            'id' => 'evt_test_checkout_1',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'client_reference_id' => (string) $user->id,
                    'customer' => 'cus_test_1',
                    'subscription' => 'sub_test_1',
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'membership_plan_id' => (string) $plan->id,
                    ],
                ],
            ],
        ];

        $this->postJson('/stripe/webhook', $payload, ['Stripe-Signature' => 'test'])
            ->assertOk();

        $membership = Membership::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame('cus_test_1', $membership->stripe_customer_id);
        $this->assertSame('sub_test_1', $membership->stripe_subscription_id);
        $this->assertSame($plan->id, $membership->membership_plan_id);
        $this->assertTrue($membership->isPaying());
    }

    public function test_webhook_is_idempotent_and_payment_failed_marks_past_due(): void
    {
        $user = User::factory()->create();
        $membership = app(MembershipService::class)->ensureMembership($user);
        $membership->update([
            'status' => MembershipStatus::Active,
            'stripe_subscription_id' => 'sub_fail_1',
            'stripe_customer_id' => 'cus_fail_1',
        ]);

        $event = [
            'id' => 'evt_fail_1',
            'type' => 'invoice.payment_failed',
            'data' => [
                'object' => [
                    'subscription' => 'sub_fail_1',
                ],
            ],
        ];

        $this->postJson('/stripe/webhook', $event, ['Stripe-Signature' => 'test'])->assertOk();
        $this->postJson('/stripe/webhook', $event, ['Stripe-Signature' => 'test'])->assertOk();

        $this->assertSame(MembershipStatus::PastDue, $membership->fresh()->status);
        $this->assertSame(1, StripeWebhookEvent::query()->where('event_id', 'evt_fail_1')->count());
    }

    public function test_subscription_deleted_cancels_membership(): void
    {
        $user = User::factory()->create();
        $membership = app(MembershipService::class)->ensureMembership($user);
        $membership->update([
            'status' => MembershipStatus::Active,
            'stripe_subscription_id' => 'sub_del_1',
            'stripe_customer_id' => 'cus_del_1',
        ]);

        app(MembershipBillingService::class)->handleWebhookEvent([
            'id' => 'evt_del_1',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_del_1',
                    'customer' => 'cus_del_1',
                    'current_period_end' => now()->timestamp,
                ],
            ],
        ]);

        $this->assertSame(MembershipStatus::Canceled, $membership->fresh()->status);
    }

    public function test_staff_can_update_plan_price_id(): void
    {
        $staff = User::factory()->staff()->create();
        $plan = MembershipPlan::query()->where('slug', 'monthly')->firstOrFail();

        $this->actingAs($staff)
            ->patch(route('staff.membership-plans.update', $plan), [
                'name' => 'Monthly supporter',
                'amount_pence' => 600,
                'stripe_price_id' => 'price_test_monthly',
                'is_active' => true,
            ])
            ->assertRedirect(route('staff.membership-plans.index'));

        $this->assertSame(600, $plan->fresh()->amount_pence);
        $this->assertSame('price_test_monthly', $plan->fresh()->stripe_price_id);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $this->postJson('/stripe/webhook', ['id' => 'evt_x', 'type' => 'checkout.session.completed'], [
            'Stripe-Signature' => 'bad',
        ])->assertStatus(400);
    }
}
