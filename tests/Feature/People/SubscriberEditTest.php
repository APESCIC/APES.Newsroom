<?php

namespace Tests\Feature\People;

use App\Enums\ConsentAction;
use App\Enums\MailingList;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\ConsentEvent;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\Suppression;
use App\Models\User;
use App\Notifications\ConfirmMailingListNotification;
use App\Notifications\ConfirmNewsletterNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SubscriberEditTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->staff = User::factory()->staff()->create();
    }

    public function test_email_edit_rekeys_the_contact_and_asks_for_confirmation_again(): void
    {
        $contact = MailingContact::factory()->create(['email' => 'old@example.test', 'name' => 'Sam']);
        $newsletter = $this->newsletter();
        $subscription = $this->subscribe($contact, $newsletter);
        $legacy = MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'list' => MailingList::ApesCic,
            'status' => SubscriptionStatus::Confirmed,
        ]);

        $this->edit($contact, ['name' => 'Samantha', 'email' => ' New@Example.TEST '])
            ->assertRedirect(route('staff.people.contacts.show', $contact))
            ->assertSessionHasNoErrors();

        $contact->refresh();
        $this->assertSame('new@example.test', $contact->email);
        $this->assertSame('new@example.test', $contact->email_normalized);
        $this->assertSame('Samantha', $contact->name);
        $this->assertSame(SubscriptionStatus::Pending, $subscription->fresh()->status);
        $this->assertSame(SubscriptionStatus::Pending, $legacy->fresh()->status);
        Notification::assertSentTo($contact, ConfirmNewsletterNotification::class);
        Notification::assertSentTo($contact, ConfirmMailingListNotification::class);

        $log = AuditLog::query()->where('action', 'mailing_contact.updated')->sole();
        $this->assertSame([
            'reason' => 'Subscriber asked by phone',
            'fields_changed' => ['name', 'email'],
            'reconfirmation_requested' => 2,
        ], $log->payload);
        $this->assertStringNotContainsString('example.test', json_encode($log->payload));
        $this->assertTrue(ConsentEvent::query()->where('source', 'staff')->where('action', ConsentAction::ContactUpdated)->exists());
    }

    public function test_email_edit_respects_the_unique_index_and_account_emails(): void
    {
        $contact = MailingContact::factory()->create();
        $other = MailingContact::factory()->create();
        $account = User::factory()->create();

        $this->edit($contact, ['email' => strtoupper($other->email)])->assertSessionHasErrors('email');
        $this->edit($contact, ['email' => $account->email])->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'mailing_contact.updated']);
    }

    public function test_a_suppression_follows_the_contact_to_its_new_address(): void
    {
        $contact = MailingContact::factory()->create(['email' => 'blocked@example.test']);
        Suppression::query()->create(['email' => 'blocked@example.test', 'reason' => 'unsubscribe_all']);
        $subscription = $this->subscribe($contact, $this->newsletter());

        $this->edit($contact, ['email' => 'moved@example.test'])->assertSessionHasNoErrors();

        $this->assertTrue(Suppression::query()->where('email', 'moved@example.test')->exists());
        $this->assertSame(SubscriptionStatus::Unsubscribed, $subscription->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_contacts_linked_to_an_account_are_edited_through_the_account(): void
    {
        $member = User::factory()->create();
        $contact = MailingContact::factory()->create(['user_id' => $member->id, 'email' => $member->email]);

        $this->edit($contact, ['email' => 'changed@example.test'])->assertForbidden();
        $this->assertSame($member->email, $contact->fresh()->email);
    }

    public function test_staff_unsubscribe_one_newsletter_with_a_staff_consent_event(): void
    {
        $contact = MailingContact::factory()->create();
        $keep = $this->subscribe($contact, $this->newsletter('Keep'));
        $drop = $this->subscribe($contact, $this->newsletter('Drop'));

        $this->asStaff()->post(route('staff.people.contacts.subscriptions.unsubscribe', [$contact, $drop]), ['reason' => 'Asked to stop'])
            ->assertRedirect(route('staff.people.contacts.show', $contact));

        $this->assertSame(SubscriptionStatus::Unsubscribed, $drop->fresh()->status);
        $this->assertSame(SubscriptionStatus::Confirmed, $keep->fresh()->status);

        $event = ConsentEvent::query()->where('action', ConsentAction::Unsubscribed)->sole();
        $this->assertSame('staff', $event->source);
        $this->assertSame($this->staff->id, $event->evidence['actor_id']);
        $this->assertSame('Asked to stop', $event->evidence['reason']);
        $this->assertSame(['reason' => 'Asked to stop', 'subscription_id' => $drop->id, 'newsletter_id' => $drop->newsletter_id], AuditLog::query()->where('action', 'mailing_subscription.unsubscribed')->sole()->payload);
    }

    public function test_a_subscription_from_another_contact_cannot_be_targeted(): void
    {
        $contact = MailingContact::factory()->create();
        $other = $this->subscribe(MailingContact::factory()->create(), $this->newsletter());

        $this->asStaff()->post(route('staff.people.contacts.subscriptions.unsubscribe', [$contact, $other]), ['reason' => 'Wrong person'])
            ->assertNotFound();
        $this->assertSame(SubscriptionStatus::Confirmed, $other->fresh()->status);
    }

    public function test_resubscribe_only_sends_double_opt_in_and_stays_pending(): void
    {
        $contact = MailingContact::factory()->create();
        $newsletter = $this->newsletter();
        $subscription = $this->subscribe($contact, $newsletter, SubscriptionStatus::Unsubscribed);

        $this->asStaff()->post(route('staff.people.contacts.newsletters.subscribe', [$contact, $newsletter]), ['reason' => 'Wants it back'])
            ->assertSessionHasNoErrors();

        $this->assertSame(SubscriptionStatus::Pending, $subscription->fresh()->status);
        $this->assertNull($subscription->fresh()->confirmed_at);
        Notification::assertSentTo($contact, ConfirmNewsletterNotification::class);
        $event = ConsentEvent::query()->where('action', ConsentAction::SignupRequested)->sole();
        $this->assertSame('staff', $event->source);
        $this->assertSame($this->staff->id, $event->evidence['actor_id']);
    }

    public function test_resubscribe_is_refused_while_suppressed(): void
    {
        $contact = MailingContact::factory()->create();
        Suppression::query()->create(['email' => $contact->email_normalized, 'reason' => 'unsubscribe_all']);

        $this->asStaff()->post(route('staff.people.contacts.newsletters.subscribe', [$contact, $this->newsletter()]), ['reason' => 'Wants it back'])
            ->assertSessionHasErrors('subscription');

        $this->assertDatabaseCount('mailing_list_subscriptions', 0);
        Notification::assertNothingSent();
    }

    public function test_suppress_and_unsuppress_are_audited_without_resubscribing(): void
    {
        $contact = MailingContact::factory()->create();
        $subscription = $this->subscribe($contact, $this->newsletter(), SubscriptionStatus::Unsubscribed);

        $this->asStaff()->post(route('staff.people.contacts.suppress', $contact), ['reason' => 'Bounced repeatedly']);
        $this->assertTrue(Suppression::query()->where('email', $contact->email_normalized)->exists());

        $this->asStaff()->delete(route('staff.people.contacts.unsuppress', $contact), ['reason' => 'Mailbox fixed']);
        $this->assertFalse(Suppression::query()->where('email', $contact->email_normalized)->exists());
        $this->assertSame(SubscriptionStatus::Unsubscribed, $subscription->fresh()->status);

        $this->assertSame(['staff'], ConsentEvent::query()->whereIn('action', [ConsentAction::Suppressed, ConsentAction::Unsuppressed])->pluck('source')->unique()->values()->all());
        $this->assertSame(['reason' => 'Bounced repeatedly'], AuditLog::query()->where('action', 'mailing_contact.suppressed')->sole()->payload);
        $this->assertSame(['reason' => 'Mailbox fixed'], AuditLog::query()->where('action', 'mailing_contact.unsuppressed')->sole()->payload);
    }

    public function test_only_admins_delete_and_consent_evidence_is_kept(): void
    {
        $contact = MailingContact::factory()->create();
        $this->subscribe($contact, $this->newsletter());
        ConsentEvent::query()->create([
            'mailing_contact_id' => $contact->id,
            'email' => $contact->email,
            'action' => ConsentAction::Confirmed,
            'source' => 'double_opt_in',
            'created_at' => now(),
        ]);
        $payload = ['reason' => 'Requested erasure', 'confirm_email' => $contact->email, 'suppress' => true];

        $this->asStaff()->delete(route('staff.people.contacts.destroy', $contact), $payload)->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->withRecentAuthentication()
            ->delete(route('staff.people.contacts.destroy', $contact), [...$payload, 'confirm_email' => 'wrong@example.test'])
            ->assertSessionHasErrors('confirm_email');

        $this->actingAs($admin)->withRecentAuthentication()
            ->delete(route('staff.people.contacts.destroy', $contact), $payload)
            ->assertRedirect(route('staff.subscribers.index'));

        $this->assertModelMissing($contact);
        $this->assertDatabaseCount('mailing_list_subscriptions', 0);
        $this->assertTrue(Suppression::query()->where('email', $contact->email_normalized)->exists());
        $this->assertSame(2, ConsentEvent::query()->whereNull('mailing_contact_id')->count());
        $this->assertSame(['reason' => 'Requested erasure', 'suppressed' => true], AuditLog::query()->where('action', 'mailing_contact.deleted')->sole()->payload);
    }

    public function test_every_change_needs_a_reason(): void
    {
        $contact = MailingContact::factory()->create();

        $this->edit($contact, ['name' => 'X', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->asStaff()->post(route('staff.people.contacts.suppress', $contact), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function asStaff(): static
    {
        return $this->actingAs($this->staff)->withRecentAuthentication();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function edit(MailingContact $contact, array $data)
    {
        return $this->asStaff()->patch(route('staff.people.contacts.update', $contact), [
            'name' => $contact->name,
            'email' => $contact->email,
            'reason' => 'Subscriber asked by phone',
            ...$data,
        ]);
    }

    private function newsletter(string $name = 'Rescue diary'): Newsletter
    {
        return Newsletter::query()->create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid()]);
    }

    private function subscribe(MailingContact $contact, Newsletter $newsletter, SubscriptionStatus $status = SubscriptionStatus::Confirmed): MailingListSubscription
    {
        return MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'newsletter_id' => $newsletter->id,
            'status' => $status,
            'confirmed_at' => $status === SubscriptionStatus::Confirmed ? now() : null,
            'unsubscribed_at' => $status === SubscriptionStatus::Unsubscribed ? now() : null,
        ]);
    }
}
