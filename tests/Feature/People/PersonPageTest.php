<?php

namespace Tests\Feature\People;

use App\Enums\SubscriptionStatus;
use App\Models\ContentView;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Membership;
use App\Models\Newsletter;
use App\Models\Profile;
use App\Models\Suppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_account_page_joins_account_membership_contact_subscriptions_and_profile(): void
    {
        $member = User::factory()->withContactDetails()->create();
        Membership::query()->create(['user_id' => $member->id, 'status' => 'active', 'interval' => 'month']);
        $contact = MailingContact::factory()->create(['user_id' => $member->id, 'email' => $member->email]);
        $newsletter = Newsletter::query()->create(['name' => 'Rescue diary', 'slug' => 'rescue-diary']);
        MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'newsletter_id' => $newsletter->id,
            'status' => SubscriptionStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
        Profile::query()->create(['user_id' => $member->id, 'display_name' => 'Rescue fan', 'bio' => 'Hello']);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.people.users.show', $member))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/People/Show')
                ->where('person.kind', 'user')
                ->where('person.user.email', $member->email)
                ->where('person.contact_details.address_town', 'Leeds')
                ->where('person.membership.is_paying', true)
                ->where('person.mailing.contact_id', $contact->id)
                ->where('person.mailing.subscriptions', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['label'] === 'Rescue diary' && $row['status'] === 'confirmed',
                ))
                ->where('person.profile.display_name', 'Rescue fan')
                ->where('person.membership.note', null)
                ->where('person.can.update', true)
                ->where('person.can.manage_comp', false)
                ->where('person.can.edit_note', false)
                ->where('person.can.manage_subscriptions', false)
                ->where('person.can.view_admin_user', false));
    }

    public function test_admins_see_the_member_controls(): void
    {
        $member = User::factory()->create();
        MailingContact::factory()->create(['user_id' => $member->id, 'email' => $member->email]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('staff.people.users.show', $member))
            ->assertInertia(fn (Assert $page) => $page
                ->where('person.can.manage_comp', true)
                ->where('person.can.edit_note', true)
                ->where('person.can.manage_subscriptions', true)
                ->where('person.can.view_admin_user', true)
                ->where('person.membership.note.text', null));
    }

    public function test_subscriber_only_contact_page_works_without_an_account(): void
    {
        $contact = MailingContact::factory()->create(['name' => 'Sam Subscriber']);
        Suppression::query()->create(['email' => $contact->email_normalized, 'reason' => 'unsubscribe_all']);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.people.contacts.show', $contact))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/People/Show')
                ->where('person.kind', 'contact')
                ->where('person.contact.name', 'Sam Subscriber')
                ->where('person.mailing.suppressed', true)
                ->where('person.can.update', true)
                ->where('person.can.delete', false));
    }

    public function test_a_linked_contact_opens_the_account_page(): void
    {
        $member = User::factory()->create();
        $contact = MailingContact::factory()->create(['user_id' => $member->id, 'email' => $member->email]);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.people.contacts.show', $contact))
            ->assertRedirect(route('staff.people.users.show', $member));
    }

    public function test_people_list_shows_public_accounts_only(): void
    {
        $member = User::factory()->create(['name' => 'Pat Public']);
        User::factory()->staff()->create(['name' => 'Sid Staff']);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.people.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/People/Index')
                ->where('people.total', 1)
                ->where('people.data.0.id', $member->id));
    }

    public function test_people_list_never_sends_reading_history_to_staff(): void
    {
        $member = User::factory()->create();
        ContentView::query()->create([
            'user_id' => $member->id,
            'path' => '/articles/private-reading',
            'viewed_at' => now(),
        ]);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.people.index'))
            ->assertOk()
            ->assertDontSee('private-reading')
            ->assertInertia(fn (Assert $page) => $page->missing('people.data.0.recent_reads'));
    }

    public function test_legacy_members_link_now_opens_people(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/staff/members')
            ->assertRedirect('/staff/people');
    }

    public function test_the_staff_note_is_never_sent_to_staff(): void
    {
        $member = User::factory()->create();
        $membership = Membership::query()->create(['user_id' => $member->id, 'status' => 'free']);
        $membership->forceFill(['staff_note' => 'Private admin note'])->save();

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('staff.people.users.show', $member))
            ->assertOk()
            ->assertDontSee('Private admin note');
    }
}
