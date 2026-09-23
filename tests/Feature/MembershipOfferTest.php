<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Models\MembershipPlan;
use App\Models\Offer;
use App\Models\OfferRedemption;
use App\Models\User;
use App\Services\Membership\FakeStripeBillingClient;
use App\Services\Membership\MembershipBillingService;
use App\Services\Membership\MembershipService;
use App\Services\Membership\OfferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipOfferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_staff_can_create_offer_with_fake_stripe_ids(): void
    {
        $staff = User::factory()->staff()->create();
        $fake = app(FakeStripeBillingClient::class);

        $this->actingAs($staff)
            ->post(route('staff.offers.store'), [
                'code' => 'welcome10',
                'name' => 'Welcome 10%',
                'discount_type' => 'percent',
                'discount_value' => 10,
                'max_redemptions' => 50,
                'is_active' => true,
            ])
            ->assertRedirect(route('staff.offers.index'));

        $offer = Offer::query()->where('code', 'WELCOME10')->firstOrFail();
        $this->assertNotNull($offer->stripe_promotion_code_id);
        $this->assertCount(1, $fake->offers);
    }

    public function test_checkout_rejects_expired_offer(): void
    {
        $user = User::factory()->create();
        app(MembershipService::class)->ensureMembership($user);

        $offer = app(OfferService::class)->create([
            'code' => 'OLD',
            'name' => 'Old',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'ends_at' => now()->subDay()->toIso8601String(),
            'is_active' => true,
        ]);

        $this->assertFalse($offer->isCurrentlyValid());

        $this->actingAs($user)
            ->from(route('account.show'))
            ->post(route('account.membership.checkout'), [
                'plan' => 'monthly',
                'offer_code' => 'OLD',
            ])
            ->assertRedirect(route('account.show'))
            ->assertSessionHasErrors('offer_code');
    }

    public function test_valid_offer_applies_and_redeems_on_checkout_completed(): void
    {
        $user = User::factory()->create();
        app(MembershipService::class)->ensureMembership($user);
        $plan = MembershipPlan::query()->where('slug', 'monthly')->firstOrFail();

        $offer = app(OfferService::class)->create([
            'code' => 'SAVE15',
            'name' => 'Save 15%',
            'discount_type' => 'percent',
            'discount_value' => 15,
            'max_redemptions' => 5,
            'is_active' => true,
        ]);

        $fake = app(FakeStripeBillingClient::class);

        $this->actingAs($user)
            ->post(route('account.membership.checkout'), [
                'plan' => 'monthly',
                'offer_code' => 'SAVE15',
            ])
            ->assertRedirect();

        $this->assertSame($offer->stripe_promotion_code_id, $fake->checkouts[0]['promotion_code_id']);
        $this->assertSame((string) $offer->id, $fake->checkouts[0]['metadata']['offer_id']);

        app(MembershipBillingService::class)->handleWebhookEvent([
            'id' => 'evt_offer_1',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $fake->checkouts[0]['id'],
                    'client_reference_id' => (string) $user->id,
                    'customer' => 'cus_offer_1',
                    'subscription' => 'sub_offer_1',
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'membership_plan_id' => (string) $plan->id,
                        'offer_id' => (string) $offer->id,
                    ],
                ],
            ],
        ]);

        $this->assertSame(MembershipStatus::Active, $user->membership()->first()->status);
        $this->assertDatabaseHas('offer_redemptions', [
            'offer_id' => $offer->id,
            'user_id' => $user->id,
        ]);
        $this->assertSame(1, $offer->fresh()->redemption_count);
        $this->assertSame(1, OfferRedemption::query()->count());
    }

    public function test_exhausted_offer_is_rejected(): void
    {
        $user = User::factory()->create();
        app(MembershipService::class)->ensureMembership($user);

        $offer = app(OfferService::class)->create([
            'code' => 'ONCE',
            'name' => 'Once',
            'discount_type' => 'amount',
            'discount_value' => 100,
            'max_redemptions' => 1,
            'is_active' => true,
        ]);
        $offer->update(['redemption_count' => 1]);

        $this->actingAs($user)
            ->post(route('account.membership.checkout'), [
                'plan' => 'monthly',
                'offer_code' => 'ONCE',
            ])
            ->assertSessionHasErrors('offer_code');
    }
}
