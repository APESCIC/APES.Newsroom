<?php

namespace Tests\Feature;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Suppression;
use App\Models\User;
use App\Notifications\ConfirmMailingListNotification;
use App\Services\Account\AccountEmailChangeService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_change_withdraws_old_active_lists_and_requires_fresh_confirmation(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'old@example.com']);
        $oldContact = MailingContact::factory()->create([
            'email' => 'old@example.com',
            'user_id' => $user->id,
        ]);
        $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());
        $this->subscription($oldContact, MailingList::ApesShelterRescue, SubscriptionStatus::Pending);
        $this->subscription($oldContact, MailingList::ApesPetCareClinic, SubscriptionStatus::Unsubscribed);

        $this->actingAs($user)->withRecentAuthentication()->patch(route('account.update'), [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertRedirect();

        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertNull($oldContact->fresh()->user_id);
        $this->assertDatabaseHas('suppressions', [
            'email' => 'old@example.com',
            'reason' => 'account_email_change',
        ]);

        foreach ([MailingList::ApesCic, MailingList::ApesShelterRescue] as $list) {
            $this->assertDatabaseHas('mailing_list_subscriptions', [
                'mailing_contact_id' => $oldContact->id,
                'list' => $list->value,
                'status' => SubscriptionStatus::Unsubscribed->value,
            ]);
            $this->assertDatabaseHas('consent_events', [
                'email' => 'old@example.com',
                'list' => $list->value,
                'action' => ConsentAction::Unsubscribed->value,
                'source' => 'account_email_change',
            ]);
        }

        $newContact = MailingContact::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertSame($user->id, $newContact->user_id);
        $this->assertSame(2, $newContact->subscriptions()->count());
        $this->assertDatabaseMissing('mailing_list_subscriptions', [
            'mailing_contact_id' => $newContact->id,
            'list' => MailingList::ApesPetCareClinic->value,
        ]);

        foreach ([MailingList::ApesCic, MailingList::ApesShelterRescue] as $list) {
            $subscription = $newContact->subscriptions()->where('list', $list->value)->firstOrFail();
            $this->assertSame(SubscriptionStatus::Pending, $subscription->status);
            $this->assertNull($subscription->confirmed_at);
            $this->assertNotNull($subscription->confirm_token);
            $this->assertDatabaseHas('consent_events', [
                'email' => 'new@example.com',
                'list' => $list->value,
                'action' => ConsentAction::SignupRequested->value,
                'source' => 'account_email_change',
            ]);
        }

        Notification::assertSentToTimes($newContact, ConfirmMailingListNotification::class, 2);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_email_change_does_not_create_mailing_state_when_old_address_had_none(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'absent@example.com']);

        $this->actingAs($user)->withRecentAuthentication()->patch(route('account.update'), [
            'name' => $user->name,
            'email' => 'new-absent@example.com',
        ])->assertRedirect();

        $this->assertDatabaseMissing('mailing_contacts', ['email' => 'new-absent@example.com']);
        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertSentTimes(ConfirmMailingListNotification::class, 0);
    }

    public function test_suppressed_old_address_does_not_resurrect_stale_active_lists(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'suppressed@example.com']);
        $oldContact = MailingContact::factory()->create([
            'email' => 'suppressed@example.com',
            'user_id' => $user->id,
        ]);
        $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());
        Suppression::create(['email' => 'suppressed@example.com', 'reason' => 'objection']);

        $this->actingAs($user)->withRecentAuthentication()->patch(route('account.update'), [
            'name' => $user->name,
            'email' => 'replacement@example.com',
        ])->assertRedirect();

        $newContact = MailingContact::query()->where('email', 'replacement@example.com')->firstOrFail();
        $this->assertSame(0, $newContact->subscriptions()->count());
        Notification::assertNotSentTo($newContact, ConfirmMailingListNotification::class);
    }

    public function test_existing_unsubscribed_new_address_is_not_resurrected(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'source@example.com']);
        $oldContact = MailingContact::factory()->create([
            'email' => 'source@example.com',
            'user_id' => $user->id,
        ]);
        $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());

        $newContact = MailingContact::factory()->create(['email' => 'withdrawn@example.com']);
        $newSubscription = $this->subscription(
            $newContact,
            MailingList::ApesCic,
            SubscriptionStatus::Unsubscribed,
        );

        $this->actingAs($user)->withRecentAuthentication()->patch(route('account.update'), [
            'name' => $user->name,
            'email' => 'withdrawn@example.com',
        ])->assertRedirect();

        $this->assertSame(SubscriptionStatus::Unsubscribed, $newSubscription->fresh()->status);
        Notification::assertNotSentTo($newContact, ConfirmMailingListNotification::class);
    }

    public function test_suppressed_new_address_does_not_receive_recreated_subscriptions(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'source@example.com']);
        $oldContact = MailingContact::factory()->create([
            'email' => 'source@example.com',
            'user_id' => $user->id,
        ]);
        $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());
        Suppression::query()->create([
            'email' => 'blocked@example.com',
            'reason' => 'recipient_objection',
        ]);

        $this->actingAs($user)->withRecentAuthentication()->patch(route('account.update'), [
            'name' => $user->name,
            'email' => 'blocked@example.com',
        ])->assertRedirect();

        $newContact = MailingContact::query()->where('email', 'blocked@example.com')->firstOrFail();
        $this->assertSame($user->id, $newContact->user_id);
        $this->assertSame(0, $newContact->subscriptions()->count());
        Notification::assertNotSentTo($newContact, ConfirmMailingListNotification::class);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_outer_transaction_rollback_restores_consent_state_and_discards_notifications(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'rollback-old@example.com']);
        $oldContact = MailingContact::factory()->create([
            'email' => 'rollback-old@example.com',
            'user_id' => $user->id,
        ]);
        $oldSubscription = $this->subscription(
            $oldContact,
            MailingList::ApesCic,
            SubscriptionStatus::Confirmed,
            now(),
        );

        try {
            DB::transaction(function () use ($user) {
                app(AccountEmailChangeService::class)->update(
                    $user,
                    ['name' => $user->name, 'email' => 'rollback-new@example.com'],
                    '127.0.0.1',
                    'account-email-change-test',
                );

                throw new \RuntimeException('Force the outer account update to roll back.');
            });

            $this->fail('The outer transaction unexpectedly committed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Force the outer account update to roll back.', $exception->getMessage());
        }

        $this->assertSame('rollback-old@example.com', $user->fresh()->email);
        $this->assertSame($user->id, $oldContact->fresh()->user_id);
        $this->assertSame(SubscriptionStatus::Confirmed, $oldSubscription->fresh()->status);
        $this->assertDatabaseMissing('mailing_contacts', ['email' => 'rollback-new@example.com']);
        $this->assertDatabaseMissing('suppressions', ['email' => 'rollback-old@example.com']);
        $this->assertDatabaseMissing('consent_events', ['source' => 'account_email_change']);
        Notification::assertNothingSent();
    }

    public function test_oidc_managed_email_cannot_be_changed_directly(): void
    {
        $user = User::factory()->staff()->create(['email' => 'staff@example.com']);

        $this->actingAs($user)->withRecentAuthentication()->patch(route('account.update'), [
            'name' => $user->name,
            'email' => 'changed@example.com',
        ])->assertSessionHasErrors('email');

        $this->assertSame('staff@example.com', $user->fresh()->email);
    }

    private function subscription(
        MailingContact $contact,
        MailingList $list,
        SubscriptionStatus $status,
        mixed $confirmedAt = null,
    ): MailingListSubscription {
        return MailingListSubscription::create([
            'mailing_contact_id' => $contact->id,
            'list' => $list,
            'status' => $status,
            'confirm_token' => $status === SubscriptionStatus::Pending ? str_repeat('a', 64) : null,
            'confirmed_at' => $confirmedAt,
            'unsubscribed_at' => $status === SubscriptionStatus::Unsubscribed ? now() : null,
        ]);
    }
}
