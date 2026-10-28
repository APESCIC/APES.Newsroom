<?php

namespace Tests\Feature\People;

use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\MailingContact;
use App\Models\MailingListSubscription;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The roles matrix from #567: which role may take each people action.
 */
class PeopleAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
    }

    /**
     * @return array<string, array{0: string, 1: Role}>
     */
    public static function actions(): array
    {
        return [
            'view people list' => ['view_people', Role::Staff],
            'view a public account' => ['view_user', Role::Staff],
            'view a subscriber' => ['view_contact', Role::Staff],
            'edit a subscriber' => ['edit_contact', Role::Staff],
            'unsubscribe a subscriber' => ['unsubscribe_contact', Role::Staff],
            'suppress a subscriber' => ['suppress_contact', Role::Staff],
            'delete a subscriber' => ['delete_contact', Role::Admin],
            'edit public account contact details' => ['edit_user', Role::Staff],
            'edit a public profile' => ['edit_profile', Role::Staff],
            'grant complimentary access' => ['comp', Role::Admin],
            'edit the member note' => ['note', Role::Admin],
            'toggle a member newsletter' => ['member_newsletter', Role::Admin],
            'lock an account' => ['lock', Role::Admin],
            'change a role' => ['role', Role::SuperAdmin],
            'erase an account' => ['erase', Role::SuperAdmin],
        ];
    }

    #[DataProvider('actions')]
    public function test_action_is_limited_to_its_minimum_role(string $action, Role $minimum): void
    {
        foreach (Role::cases() as $role) {
            $response = $this->perform($action, $this->actor($role));

            if ($role->atLeast($minimum)) {
                $this->assertNotContains($response->getStatusCode(), [401, 403], "{$role->value} should be allowed to {$action}.");
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_staff_cannot_view_or_edit_staff_and_admin_accounts(): void
    {
        $staff = $this->actor(Role::Staff);

        foreach ([User::factory()->staff()->create(), User::factory()->admin()->create()] as $target) {
            $this->actingAs($staff)->get(route('staff.people.users.show', $target))->assertForbidden();
            $this->actingAs($staff)->withRecentAuthentication()
                ->patch(route('staff.people.users.update', $target), ['name' => 'X', 'email' => $target->email, 'reason' => 'Checking'])
                ->assertForbidden();
        }
    }

    private function perform(string $action, User $actor): TestResponse
    {
        $member = User::factory()->create();
        $contact = MailingContact::factory()->create();
        $linked = MailingContact::factory()->create(['user_id' => $member->id, 'email' => $member->email]);
        $newsletter = Newsletter::query()->create(['name' => 'Matrix news', 'slug' => 'matrix-news-'.$member->id]);
        $subscription = MailingListSubscription::query()->create([
            'mailing_contact_id' => $contact->id,
            'newsletter_id' => $newsletter->id,
            'status' => SubscriptionStatus::Confirmed,
        ]);
        $reason = ['reason' => 'Matrix check'];
        $request = $this->actingAs($actor)->withRecentAuthentication();

        return match ($action) {
            'view_people' => $request->get(route('staff.people.index')),
            'view_user' => $request->get(route('staff.people.users.show', $member)),
            'view_contact' => $request->get(route('staff.people.contacts.show', $contact)),
            'edit_contact' => $request->patch(route('staff.people.contacts.update', $contact), ['name' => 'New', 'email' => $contact->email, ...$reason]),
            'unsubscribe_contact' => $request->post(route('staff.people.contacts.subscriptions.unsubscribe', [$contact, $subscription]), $reason),
            'suppress_contact' => $request->post(route('staff.people.contacts.suppress', $contact), $reason),
            'delete_contact' => $request->delete(route('staff.people.contacts.destroy', $contact), ['confirm_email' => $contact->email, ...$reason]),
            'edit_user' => $request->patch(route('staff.people.users.update', $member), ['name' => 'Renamed', 'email' => $member->email, ...$reason]),
            'edit_profile' => $request->post(route('staff.people.users.profile.update', $member), ['display_name' => 'Reader', ...$reason]),
            'comp' => $request->post(route('staff.people.users.comp.store', $member), $reason),
            'note' => $request->patch(route('staff.people.users.note.update', $member), ['note' => 'Called in', ...$reason]),
            'member_newsletter' => $request->post(route('staff.people.contacts.newsletters.subscribe', [$linked, $newsletter]), $reason),
            'lock' => $request->post(route('admin.users.lock', $member), $reason),
            'role' => $request->patch(route('admin.users.role', $member), ['role' => Role::Staff->value, ...$reason]),
            'erase' => $request->delete(route('admin.users.destroy', $member), ['confirm_email' => $member->email, ...$reason]),
        };
    }

    private function actor(Role $role): User
    {
        $factory = User::factory();

        return match ($role) {
            Role::Public => $factory->create(),
            Role::Staff => $factory->staff()->create(),
            Role::Admin => $factory->admin()->create(),
            Role::SuperAdmin => $factory->superAdmin()->create(),
        };
    }
}
