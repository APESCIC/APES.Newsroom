<?php

namespace Tests\Feature;

use App\Enums\MembershipStatus;
use App\Enums\SubscriptionStatus;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MembershipPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_registration_creates_free_membership_and_unlinked_mailing_contact_without_subscribe(): void
    {
        $this->post('/register', [
            'name' => 'Jamie Fox',
            'email' => 'jamie@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'jamie@example.com')->firstOrFail();
        $membership = Membership::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($membership);
        $this->assertSame(MembershipStatus::Free, $membership->status);
        $this->assertNull($membership->interval);

        $contact = MailingContact::query()->where('email', 'jamie@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertNull($contact->user_id);
        $this->assertSame(0, MailingListSubscription::query()->where('mailing_contact_id', $contact->id)->count());
    }

    public function test_registration_does_not_claim_existing_mailing_contact_until_verified(): void
    {
        $contact = MailingContact::query()->create(['email' => 'reader@example.com']);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'list' => 'apes_cic',
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $this->post('/register', [
            'name' => 'Reader',
            'email' => 'reader@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect();

        $user = User::query()->where('email', 'reader@example.com')->firstOrFail();
        $contact->refresh();

        $this->assertNull($contact->user_id);
        $this->assertSame(1, $contact->subscriptions()->count());
        $this->assertSame(SubscriptionStatus::Confirmed, $contact->subscriptions()->first()->status);

        $this->actingAs($user)->get(URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        ))->assertRedirect();

        $contact->refresh();
        $this->assertSame($user->id, $contact->user_id);
        $this->assertTrue($user->fresh()->matched_mailing_notice_pending);
    }

    public function test_account_portal_shows_free_membership_and_newsletter_link(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Profile')
                ->where('membership.status', 'free')
                ->where('membership.status_label', 'Free member')
                ->where('membership.is_paying', false));

        $this->assertDatabaseHas('memberships', [
            'user_id' => $user->id,
            'status' => 'free',
        ]);
        $this->assertDatabaseHas('mailing_contacts', [
            'email' => $user->email,
            'user_id' => $user->id,
        ]);
    }
}
