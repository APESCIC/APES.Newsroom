<?php

namespace Tests\Feature;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\EmailChangeRequest;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Suppression;
use App\Models\User;
use App\Models\UserSession;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\ConfirmMailingListNotification;
use App\Notifications\Security\EmailChangeRequestedNotification;
use App\Services\Account\AccountEmailChangeService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountEmailChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_requesting_a_change_keeps_the_current_email_until_confirmed(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $oldContact = MailingContact::factory()->create(['email' => 'old@example.com', 'user_id' => $user->id]);
        $subscription = $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());

        $this->requestChange($user, 'new@example.com', name: 'Renamed Reader')
            ->assertSessionHas('status', 'email-change-requested');

        $user->refresh();
        $this->assertSame('old@example.com', $user->email);
        $this->assertSame('Renamed Reader', $user->name);
        $this->assertSame(SubscriptionStatus::Confirmed, $subscription->fresh()->status);
        $this->assertDatabaseMissing('suppressions', ['email' => 'old@example.com']);
        $this->assertDatabaseHas('email_change_requests', ['user_id' => $user->id, 'new_email' => 'new@example.com']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.email_change_requested', 'actor_id' => $user->id]);

        Notification::assertSentOnDemand(
            ConfirmEmailChangeNotification::class,
            fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'new@example.com',
        );
        Notification::assertSentTo(
            $user,
            EmailChangeRequestedNotification::class,
            fn (EmailChangeRequestedNotification $notification) => $notification->maskedNewEmail === 'n***@example.com',
        );
        Notification::assertNotSentTo($user, VerifyEmail::class);
    }

    public function test_confirming_withdraws_old_active_lists_and_requires_fresh_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'email_verified_at' => now()->subYear()]);
        $oldContact = MailingContact::factory()->create(['email' => 'old@example.com', 'user_id' => $user->id]);
        $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());
        $this->subscription($oldContact, MailingList::ApesShelterRescue, SubscriptionStatus::Pending);
        $this->subscription($oldContact, MailingList::ApesPetCareClinic, SubscriptionStatus::Unsubscribed);

        $this->requestChange($user, 'new@example.com');
        $this->confirm('new@example.com');

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertTrue($user->email_verified_at->isToday());
        $this->assertNull($oldContact->fresh()->user_id);
        $this->assertDatabaseHas('suppressions', ['email' => 'old@example.com', 'reason' => 'account_email_change']);

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
        Notification::assertNotSentTo($user, VerifyEmail::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.email_changed', 'actor_id' => $user->id]);
    }

    public function test_confirming_does_not_create_mailing_state_when_old_address_had_none(): void
    {
        $user = User::factory()->create(['email' => 'absent@example.com']);

        $this->requestChange($user, 'new-absent@example.com');
        $this->confirm('new-absent@example.com');

        $this->assertSame('new-absent@example.com', $user->fresh()->email);
        $this->assertDatabaseMissing('mailing_contacts', ['email' => 'new-absent@example.com']);
        Notification::assertSentTimes(ConfirmMailingListNotification::class, 0);
    }

    public function test_suppressed_old_address_does_not_resurrect_stale_active_lists(): void
    {
        $user = User::factory()->create(['email' => 'suppressed@example.com']);
        $oldContact = MailingContact::factory()->create(['email' => 'suppressed@example.com', 'user_id' => $user->id]);
        $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());
        Suppression::create(['email' => 'suppressed@example.com', 'reason' => 'objection']);

        $this->requestChange($user, 'replacement@example.com');
        $this->confirm('replacement@example.com');

        $newContact = MailingContact::query()->where('email', 'replacement@example.com')->firstOrFail();
        $this->assertSame(0, $newContact->subscriptions()->count());
        Notification::assertNotSentTo($newContact, ConfirmMailingListNotification::class);
    }

    public function test_existing_unsubscribed_new_address_is_not_resurrected(): void
    {
        $user = User::factory()->create(['email' => 'source@example.com']);
        $oldContact = MailingContact::factory()->create(['email' => 'source@example.com', 'user_id' => $user->id]);
        $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());
        $newContact = MailingContact::factory()->create(['email' => 'withdrawn@example.com']);
        $newSubscription = $this->subscription($newContact, MailingList::ApesCic, SubscriptionStatus::Unsubscribed);

        $this->requestChange($user, 'withdrawn@example.com');
        $this->confirm('withdrawn@example.com');

        $this->assertSame(SubscriptionStatus::Unsubscribed, $newSubscription->fresh()->status);
        Notification::assertNotSentTo($newContact, ConfirmMailingListNotification::class);
    }

    public function test_suppressed_new_address_does_not_receive_recreated_subscriptions(): void
    {
        $user = User::factory()->create(['email' => 'source@example.com']);
        $oldContact = MailingContact::factory()->create(['email' => 'source@example.com', 'user_id' => $user->id]);
        $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());
        Suppression::query()->create(['email' => 'blocked@example.com', 'reason' => 'recipient_objection']);

        $this->requestChange($user, 'blocked@example.com');
        $this->confirm('blocked@example.com');

        $newContact = MailingContact::query()->where('email', 'blocked@example.com')->firstOrFail();
        $this->assertSame($user->id, $newContact->user_id);
        $this->assertSame(0, $newContact->subscriptions()->count());
        Notification::assertNotSentTo($newContact, ConfirmMailingListNotification::class);
    }

    public function test_outer_transaction_rollback_restores_consent_state_and_discards_notifications(): void
    {
        $user = User::factory()->create(['email' => 'rollback-old@example.com']);
        $oldContact = MailingContact::factory()->create(['email' => 'rollback-old@example.com', 'user_id' => $user->id]);
        $oldSubscription = $this->subscription($oldContact, MailingList::ApesCic, SubscriptionStatus::Confirmed, now());
        $this->requestChange($user, 'rollback-new@example.com');
        [$change, $token] = $this->pendingChangeAndToken('rollback-new@example.com');
        Notification::fake();

        try {
            DB::transaction(function () use ($change, $token) {
                app(AccountEmailChangeService::class)->confirm($change, $token, '127.0.0.1', 'account-email-change-test');

                throw new \RuntimeException('Force the outer account update to roll back.');
            });

            $this->fail('The outer transaction unexpectedly committed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Force the outer account update to roll back.', $exception->getMessage());
        }

        $this->assertSame('rollback-old@example.com', $user->fresh()->email);
        $this->assertNull($change->fresh()->confirmed_at);
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

        $this->requestChange($user, 'changed@example.com')->assertSessionHasErrors('email');

        $this->assertSame('staff@example.com', $user->fresh()->email);
        $this->assertDatabaseCount('email_change_requests', 0);
    }

    public function test_a_name_only_update_does_not_start_an_email_change(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->patch(route('account.update'), ['name' => 'New Name', 'email' => $user->email])
            ->assertSessionHas('status', 'profile-updated');

        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertDatabaseCount('email_change_requests', 0);
        Notification::assertNothingSent();
    }

    public function test_opening_a_link_changes_nothing_until_the_button_is_pressed(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->requestChange($user, 'new@example.com');

        $this->get($this->confirmUrl('new@example.com'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/EmailChange')
                ->where('state', 'confirm')
                ->where('new_email', 'n***@example.com'));
        $this->get($this->cancelUrl($user))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'cancel'));

        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->assertTrue(EmailChangeRequest::query()->sole()->isPending());
    }

    public function test_links_must_be_signed(): void
    {
        $user = User::factory()->create();
        $this->requestChange($user, 'new@example.com');
        $change = EmailChangeRequest::query()->sole();

        $this->post(route('account.email.confirm.store', ['emailChange' => $change, 'token' => 'guess']))->assertForbidden();
        $this->post(route('account.email.cancel.store', ['emailChange' => $change]))->assertForbidden();

        $this->assertTrue($change->fresh()->isPending());
    }

    public function test_the_old_address_can_cancel_the_change(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->requestChange($user, 'new@example.com');
        $confirmUrl = $this->confirmUrl('new@example.com');

        $this->post($this->cancelUrl($user))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'cancelled'));

        $this->post($confirmUrl)
            ->assertInertia(fn (Assert $page) => $page->where('state', 'unavailable'));
        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.email_change_cancelled', 'actor_id' => $user->id]);
    }

    public function test_an_expired_link_cannot_be_used(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->requestChange($user, 'new@example.com');
        $confirmUrl = $this->confirmUrl('new@example.com');

        $this->travel(61)->minutes();

        $this->get($confirmUrl)->assertInertia(fn (Assert $page) => $page->where('state', 'unavailable'));
        $this->post($confirmUrl)->assertInertia(fn (Assert $page) => $page
            ->where('state', 'unavailable')
            ->where('message', fn (string $message) => str_contains($message, 'expired')));
        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_confirmation_fails_if_the_address_was_taken_in_the_meantime(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->requestChange($user, 'contested@example.com');
        User::factory()->create(['email' => 'contested@example.com']);

        $this->post($this->confirmUrl('contested@example.com'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('state', 'unavailable')
                ->where('message', 'That email address is now used by another account.'));

        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_confirming_while_signed_in_keeps_this_session_and_ends_the_others(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $other = UserSession::factory()->for($user)->create();
        $this->requestChange($user, 'new@example.com');

        $this->post($this->confirmUrl('new@example.com'))
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('status', 'email-changed');

        $this->assertNotNull($other->fresh()->revoked_at);
        $this->get(route('account.show'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_confirming_from_a_signed_out_browser_ends_every_session(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $session = UserSession::factory()->for($user)->create();
        $epoch = $user->auth_epoch;
        $this->requestChange($user, 'new@example.com');
        $confirmUrl = $this->confirmUrl('new@example.com');

        $this->post(route('logout'));
        $this->post($confirmUrl)->assertInertia(fn (Assert $page) => $page->where('state', 'confirmed'));

        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertSame($epoch + 1, $user->fresh()->auth_epoch);
        $this->assertNotNull($session->fresh()->revoked_at);
    }

    public function test_profile_shows_the_pending_address_with_resend_and_cancel(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->requestChange($user, 'new@example.com');
        $firstLink = $this->confirmUrl('new@example.com');

        $this->get(route('account.show'))->assertInertia(fn (Assert $page) => $page
            ->where('user.email', 'old@example.com')
            ->where('pending_email.email', 'new@example.com'));

        Notification::fake();
        $this->post(route('account.email.resend'))->assertSessionHas('status', 'email-change-resent');
        $this->post($firstLink)->assertInertia(fn (Assert $page) => $page
            ->where('state', 'unavailable')
            ->where('message', fn (string $message) => str_contains($message, 'latest link')));
        $this->assertNotSame($firstLink, $this->confirmUrl('new@example.com'));

        $this->delete(route('account.email.pending.destroy'))->assertSessionHas('status', 'email-change-cancelled');
        $this->get(route('account.show'))->assertInertia(fn (Assert $page) => $page->where('pending_email', null));
    }

    public function test_a_new_request_replaces_the_pending_one(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->requestChange($user, 'first@example.com');
        $firstLink = $this->confirmUrl('first@example.com');

        $this->requestChange($user, 'second@example.com');

        $this->post($firstLink)->assertInertia(fn (Assert $page) => $page->where('state', 'unavailable'));
        $this->post($this->confirmUrl('second@example.com'))->assertRedirect(route('account.show'));
        $this->assertSame('second@example.com', $user->fresh()->email);
    }

    private function requestChange(User $user, string $email, ?string $name = null): TestResponse
    {
        return $this->actingAs($user)
            ->withRecentAuthentication()
            ->patch(route('account.update'), ['name' => $name ?? $user->name, 'email' => $email]);
    }

    private function confirm(string $email): void
    {
        $this->post($this->confirmUrl($email))->assertRedirect(route('account.show'));
    }

    private function confirmUrl(string $email): string
    {
        $url = null;

        Notification::assertSentOnDemand(ConfirmEmailChangeNotification::class, function ($notification, $channels, AnonymousNotifiable $notifiable) use ($email, &$url) {
            if ($notifiable->routes['mail'] !== $email) {
                return false;
            }

            $url = $notification->url;

            return true;
        });

        return $url;
    }

    private function cancelUrl(User $user): string
    {
        $url = null;

        Notification::assertSentTo($user, EmailChangeRequestedNotification::class, function (EmailChangeRequestedNotification $notification) use ($user, &$url) {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        return $url;
    }

    /**
     * @return array{0: EmailChangeRequest, 1: string}
     */
    private function pendingChangeAndToken(string $email): array
    {
        parse_str((string) parse_url($this->confirmUrl($email), PHP_URL_QUERY), $query);

        return [EmailChangeRequest::query()->pending()->sole(), (string) $query['token']];
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
