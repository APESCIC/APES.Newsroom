<?php

namespace Tests\Feature\People;

use App\Enums\ContentVisibility;
use App\Enums\MembershipStatus;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use App\Services\Membership\ContentAccessService;
use App\Services\Membership\MemberCrmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberCompAndNoteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_complimentary_access_unlocks_paid_content_without_touching_stripe_status(): void
    {
        $member = User::factory()->create();
        $access = app(ContentAccessService::class);
        $this->assertFalse($access->evaluate(ContentVisibility::Paid, $member)['allowed']);

        $this->asAdmin()->post(route('staff.people.users.comp.store', $member), ['reason' => 'Volunteer of the year'])
            ->assertRedirect(route('staff.people.users.show', $member));

        $membership = $member->membership()->sole();
        $this->assertTrue($membership->isComped());
        $this->assertSame(MembershipStatus::Free, $membership->status);
        $this->assertSame($this->admin->id, $membership->comped_by);
        $this->assertTrue($access->evaluate(ContentVisibility::Paid, $member->fresh())['allowed']);
        $this->assertSame('Complimentary', $membership->accessLabel());
        $this->assertSame(['reason' => 'Volunteer of the year'], AuditLog::query()->where('action', 'membership.comp_granted')->sole()->payload);

        $this->asAdmin()->delete(route('staff.people.users.comp.destroy', $member), ['reason' => 'Year ended']);

        $this->assertFalse($membership->fresh()->isComped());
        $this->assertFalse($access->evaluate(ContentVisibility::Paid, $member->fresh())['allowed']);
        $this->assertSame(['reason' => 'Year ended'], AuditLog::query()->where('action', 'membership.comp_removed')->sole()->payload);
    }

    public function test_comped_members_have_their_own_crm_filter(): void
    {
        $comped = User::factory()->create();
        User::factory()->create();
        Membership::query()->create(['user_id' => $comped->id, 'status' => MembershipStatus::Free])
            ->forceFill(['comped_at' => now()])->save();

        $rows = app(MemberCrmService::class)->paginate(null, 'comped');
        $this->assertSame([$comped->id], collect($rows->items())->pluck('id')->all());
        $this->assertTrue($rows->items()[0]['is_comped']);

        $free = app(MemberCrmService::class)->paginate(null, 'free');
        $this->assertNotContains($comped->id, collect($free->items())->pluck('id')->all());
    }

    public function test_the_member_note_is_encrypted_at_rest_and_kept_out_of_the_audit_log(): void
    {
        $member = User::factory()->create();

        $this->asAdmin()->patch(route('staff.people.users.note.update', $member), [
            'note' => 'Prefers phone calls after 6pm',
            'reason' => 'Call notes',
        ])->assertRedirect(route('staff.people.users.show', $member));

        $membership = $member->membership()->sole();
        $this->assertSame('Prefers phone calls after 6pm', $membership->staff_note);
        $this->assertSame($this->admin->id, $membership->staff_note_updated_by);

        $raw = DB::table('memberships')->where('id', $membership->id)->value('staff_note');
        $this->assertStringNotContainsString('phone calls', (string) $raw);

        $log = AuditLog::query()->where('action', 'membership.note_updated')->sole();
        $this->assertSame(['reason' => 'Call notes', 'cleared' => false], $log->payload);
        $this->assertArrayNotHasKey('staff_note', $membership->toArray());
    }

    public function test_staff_cannot_comp_or_write_notes(): void
    {
        $member = User::factory()->create();
        $staff = $this->actingAs(User::factory()->staff()->create())->withRecentAuthentication();

        $staff->post(route('staff.people.users.comp.store', $member), ['reason' => 'Trying'])->assertForbidden();
        $staff->patch(route('staff.people.users.note.update', $member), ['note' => 'x', 'reason' => 'Trying'])->assertForbidden();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin)->withRecentAuthentication();
    }
}
