<?php

namespace Tests\Feature\People;

use App\Enums\ModerationStatus;
use App\Models\AuditLog;
use App\Models\ModerationAudit;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

class ProfileFieldsEditTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        $this->staff = User::factory()->staff()->create();
    }

    public function test_staff_edit_display_name_and_bio_without_changing_moderation(): void
    {
        $member = User::factory()->create();
        $profile = $this->profile($member, ['moderation_status' => ModerationStatus::Approved, 'public_opt_in' => true]);

        $this->asStaff()->post(route('staff.people.users.profile.update', $member), [
            'display_name' => 'Friendly reader',
            'bio' => '',
            'reason' => 'Removed phone number from bio',
        ])->assertRedirect(route('staff.people.users.show', $member));

        $profile->refresh();
        $this->assertSame('Friendly reader', $profile->display_name);
        $this->assertNull($profile->bio);
        $this->assertSame(ModerationStatus::Approved, $profile->moderation_status);

        $log = AuditLog::query()->where('action', 'profile.fields_updated')->sole();
        $this->assertSame(['reason' => 'Removed phone number from bio', 'fields_changed' => ['display_name', 'bio']], $log->payload);
        $this->assertStringNotContainsString('Friendly reader', json_encode($log->payload));
        $this->assertTrue(ModerationAudit::query()->where('action', 'profile_staff_edited')->exists());
    }

    #[RequiresPhpExtension('gd')]
    public function test_staff_replace_the_avatar_and_the_old_file_is_deleted(): void
    {
        $member = User::factory()->create();
        Storage::disk('public')->put('avatars/'.$member->id.'/old.jpg', 'old');
        $profile = $this->profile($member, ['avatar_path' => 'avatars/'.$member->id.'/old.jpg']);

        $this->asStaff()->post(route('staff.people.users.profile.update', $member), [
            'display_name' => $profile->display_name,
            'bio' => $profile->bio,
            'avatar' => UploadedFile::fake()->image('new.jpg', 64, 64),
            'reason' => 'Replaced an unsuitable image',
        ])->assertSessionHasNoErrors();

        $profile->refresh();
        Storage::disk('public')->assertMissing('avatars/'.$member->id.'/old.jpg');
        Storage::disk('public')->assertExists($profile->avatar_path);
    }

    public function test_reset_clears_the_fields_and_deletes_the_avatar(): void
    {
        $member = User::factory()->create();
        Storage::disk('public')->put('avatars/'.$member->id.'/face.jpg', 'img');
        $profile = $this->profile($member, ['avatar_path' => 'avatars/'.$member->id.'/face.jpg', 'moderation_status' => ModerationStatus::Suspended]);

        $this->asStaff()->post(route('staff.people.users.profile.reset', $member), ['reason' => 'Offensive content'])
            ->assertRedirect(route('staff.people.users.show', $member));

        $profile->refresh();
        $this->assertNull($profile->display_name);
        $this->assertNull($profile->bio);
        $this->assertNull($profile->avatar_path);
        $this->assertSame(ModerationStatus::Suspended, $profile->moderation_status);
        Storage::disk('public')->assertMissing('avatars/'.$member->id.'/face.jpg');
        $this->assertSame(
            ['reason' => 'Offensive content', 'fields_reset' => ['display_name', 'bio', 'avatar']],
            AuditLog::query()->where('action', 'profile.fields_reset')->sole()->payload,
        );
    }

    public function test_reset_from_the_moderation_queue_returns_there(): void
    {
        $member = User::factory()->create();
        $this->profile($member);

        $this->actingAs(User::factory()->admin()->create())->withRecentAuthentication()
            ->post(route('staff.people.users.profile.reset', $member), ['reason' => 'Spam profile', 'return_to' => 'moderation'])
            ->assertRedirect(route('admin.moderation.index'));
    }

    public function test_reason_is_required_and_staff_cannot_edit_staff_profiles(): void
    {
        $member = User::factory()->create();
        $this->profile($member);
        $colleague = User::factory()->staff()->create();
        $this->profile($colleague);

        $this->asStaff()->post(route('staff.people.users.profile.reset', $member), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->asStaff()->post(route('staff.people.users.profile.reset', $colleague), ['reason' => 'Not allowed'])->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);

        $admin = User::factory()->admin()->create();
        $this->asStaff()->post(route('staff.people.users.profile.update', $admin), ['display_name' => 'X', 'reason' => 'Not allowed'])->assertForbidden();
        $this->assertDatabaseMissing('profiles', ['user_id' => $admin->id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function profile(User $user, array $attributes = []): Profile
    {
        return Profile::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Reader '.$user->id,
            'bio' => 'Call me on 07700 900123',
            'moderation_status' => ModerationStatus::Pending,
            ...$attributes,
        ]);
    }

    private function asStaff(): static
    {
        return $this->actingAs($this->staff)->withRecentAuthentication();
    }
}
