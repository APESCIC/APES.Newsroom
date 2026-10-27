<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_edit_page_shows_editable_fields_and_contact_details(): void
    {
        $user = User::factory()->withContactDetails()->create();

        $this->actingAs($this->admin)->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Users/Edit')
                ->where('user.id', $user->id)
                ->where('user.sso_managed', false)
                ->where('user.contact_details.mobile_number', '+447700900123'));
    }

    public function test_detail_page_shows_contact_details_and_permissions(): void
    {
        $user = User::factory()->unverified()->withContactDetails()->create();

        $this->actingAs($this->admin)->get(route('admin.users.show', $user))
            ->assertInertia(fn (Assert $page) => $page
                ->where('user.contact_details.address_town', 'Leeds')
                ->where('user.can.update', true)
                ->where('user.can.resend_verification', true)
                ->where('user.can.erase', false)
                ->where('user.erase_mode', null));
    }

    public function test_contact_details_stay_out_of_the_user_list(): void
    {
        User::factory()->withContactDetails()->create();

        $this->actingAs($this->admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertDontSee('7700900123')
            ->assertDontSee('Example Street');
    }

    public function test_admin_updates_name_and_contact_details_with_a_redacted_audit_entry(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->update($user, [
            'name' => 'New Name',
            'email' => $user->email,
            'mobile_number' => '07700 900123',
            'address_line1' => '1 High Street',
            'address_postcode' => 'ls1 4ap',
        ])->assertRedirect(route('admin.users.show', $user))->assertSessionHas('status', 'Changes saved.');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('+447700900123', $user->mobile_number);
        $this->assertSame('LS1 4AP', $user->address_postcode);

        $log = AuditLog::query()->where('action', 'user.updated')->sole();
        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame($user->id, $log->subject_id);
        $this->assertSame([
            'reason' => 'Member phoned in',
            'name' => ['from' => 'Old Name', 'to' => 'New Name'],
            'contact_details_changed' => ['address', 'mobile_number'],
        ], $log->payload);
        $this->assertStringNotContainsString('High Street', json_encode($log->payload));
    }

    public function test_email_changes_wait_for_the_user_to_confirm(): void
    {
        $user = User::factory()->create(['email' => 'old@example.test']);

        $this->update($user, ['name' => $user->name, 'email' => 'New@Example.test'])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'confirms'));

        $this->assertSame('old@example.test', $user->fresh()->email);
        $this->assertSame('new@example.test', $user->emailChangeRequests()->pending()->sole()->new_email);
        Notification::assertSentOnDemand(ConfirmEmailChangeNotification::class);
        $this->assertTrue(AuditLog::query()->where('action', 'user.updated')->sole()->payload['email_change_requested']);
    }

    public function test_sso_name_and_email_cannot_be_changed_but_contact_details_can(): void
    {
        $staff = User::factory()->staff()->create();

        $this->update($staff, ['name' => 'Renamed', 'email' => 'other@example.test'])
            ->assertSessionHasErrors(['name', 'email']);

        $this->update($staff, ['name' => $staff->name, 'email' => $staff->email, 'mobile_number' => '07911 123456'])
            ->assertSessionHasNoErrors();
        $this->assertSame('+447911123456', $staff->fresh()->mobile_number);
    }

    public function test_unchanged_submission_writes_no_audit_entry(): void
    {
        $user = User::factory()->create();

        $this->update($user, ['name' => $user->name, 'email' => $user->email])
            ->assertSessionHas('status', 'No changes to save.');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.updated']);
    }

    public function test_edit_needs_reason_recent_authentication_and_rank(): void
    {
        $user = User::factory()->create();
        $peer = User::factory()->admin()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.users.update', $user), ['name' => 'X', 'email' => $user->email, 'reason' => 'Fix'])
            ->assertRedirect(route('account.confirm-identity'));
        $this->update($user, ['name' => 'X', 'email' => $user->email, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->update($peer, ['name' => 'X', 'email' => $peer->email])->assertForbidden();
        $this->update($this->admin, ['name' => 'X', 'email' => $this->admin->email])->assertForbidden();
        $this->actingAs(User::factory()->staff()->create())->get(route('admin.users.edit', $user))->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.updated']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function update(User $user, array $data)
    {
        return $this->actingAs($this->admin)->withRecentAuthentication()
            ->from(route('admin.users.edit', $user))
            ->patch(route('admin.users.update', $user), ['reason' => 'Member phoned in', ...$data]);
    }
}
