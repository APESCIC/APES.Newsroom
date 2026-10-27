<?php

namespace Tests\Feature\People;

use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\ConfirmNewsletterNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class StaffEmailChangeTest extends TestCase
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

    public function test_staff_only_start_the_change_and_it_applies_when_the_member_confirms(): void
    {
        $member = User::factory()->create(['email' => 'reader@example.test']);

        $this->update($member, ['email' => 'Reader.New@Example.test'])
            ->assertRedirect(route('staff.people.users.show', $member))
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'confirms'));

        $this->assertSame('reader@example.test', $member->fresh()->email);
        $this->assertSame('reader.new@example.test', $member->emailChangeRequests()->pending()->sole()->new_email);

        $log = AuditLog::query()->where('action', 'user.updated')->sole();
        $this->assertSame($this->staff->id, $log->actor_id);
        $this->assertSame(['reason' => 'Member phoned in', 'email_change_requested' => true], $log->payload);

        $this->post($this->confirmUrl('reader.new@example.test'));

        $this->assertSame('reader.new@example.test', $member->fresh()->email);
    }

    public function test_newsletter_only_subscriptions_move_to_the_new_address_on_confirmation(): void
    {
        $member = User::factory()->create(['email' => 'mover@example.test']);
        $contact = MailingContact::factory()->create(['user_id' => $member->id, 'email' => 'mover@example.test']);
        $newsletter = Newsletter::query()->create(['name' => 'Rescue diary', 'slug' => 'rescue-diary']);
        $old = MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'newsletter_id' => $newsletter->id,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        $this->update($member, ['email' => 'moved@example.test']);
        $this->post($this->confirmUrl('moved@example.test'))->assertOk();

        $this->assertSame(SubscriptionStatus::Unsubscribed, $old->fresh()->status);
        $newContact = MailingContact::findByEmail('moved@example.test');
        $this->assertSame($member->id, $newContact->user_id);
        $moved = $newContact->subscriptions()->where('newsletter_id', $newsletter->id)->sole();
        $this->assertSame(SubscriptionStatus::Pending, $moved->status);
        $this->assertNull($moved->list);
        Notification::assertSentTo($newContact, ConfirmNewsletterNotification::class);
    }

    public function test_name_and_email_stay_locked_for_cloudron_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $sso = User::factory()->create(['auth_provider' => 'cloudron_oidc']);

        $this->actingAs($admin)->withRecentAuthentication()
            ->patch(route('staff.people.users.update', $sso), ['name' => 'Renamed', 'email' => 'other@example.test', 'reason' => 'Try'])
            ->assertSessionHasErrors(['name', 'email']);

        $this->assertDatabaseMissing('email_change_requests', ['user_id' => $sso->id]);
    }

    public function test_contact_details_are_audited_by_group_without_values(): void
    {
        $member = User::factory()->create();

        $this->update($member, ['mobile_number' => '07700 900123', 'address_line1' => '1 High Street', 'address_postcode' => 'LS1 4AP'])
            ->assertSessionHasNoErrors();

        $payload = AuditLog::query()->where('action', 'user.updated')->sole()->payload;
        $this->assertSame(['address', 'mobile_number'], $payload['contact_details_changed']);
        $this->assertStringNotContainsString('High Street', json_encode($payload));
        $this->assertStringNotContainsString('7700', json_encode($payload));
    }

    public function test_people_actions_write_no_personal_data_to_the_application_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $member = User::factory()->create(['email' => 'private.person@example.test']);
        $contact = MailingContact::factory()->create(['email' => 'private.subscriber@example.test']);
        $admin = User::factory()->admin()->create();

        $this->update($member, ['email' => 'private.new@example.test', 'mobile_number' => '07700 900123']);
        $this->actingAs($this->staff)->withRecentAuthentication()
            ->patch(route('staff.people.contacts.update', $contact), ['name' => 'Private Name', 'email' => 'private.moved@example.test', 'reason' => 'Asked']);
        $this->actingAs($this->staff)->withRecentAuthentication()
            ->post(route('staff.people.contacts.suppress', $contact), ['reason' => 'Asked']);
        $this->actingAs($admin)->withRecentAuthentication()
            ->patch(route('staff.people.users.note.update', $member), ['note' => 'Private note text', 'reason' => 'Asked']);

        $all = implode("\n", $logged);
        foreach (['private.person', 'private.new', 'private.subscriber', 'private.moved', 'Private Name', 'Private note text', '7700'] as $needle) {
            $this->assertStringNotContainsString($needle, $all);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(User $member, array $data)
    {
        return $this->actingAs($this->staff)->withRecentAuthentication()
            ->patch(route('staff.people.users.update', $member), [
                'name' => $member->name,
                'email' => $member->email,
                'reason' => 'Member phoned in',
                ...$data,
            ]);
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
}
