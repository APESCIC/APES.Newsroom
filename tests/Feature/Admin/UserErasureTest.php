<?php

namespace Tests\Feature\Admin;

use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\MailingContact;
use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\UserErasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserErasureTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->owner = User::factory()->superAdmin()->create();
    }

    public function test_detail_page_tells_a_super_admin_which_erasure_applies(): void
    {
        $reader = User::factory()->create();
        $author = User::factory()->staff()->create();

        $this->actingAs($this->owner)->get(route('admin.users.show', $reader))
            ->assertInertia(fn (Assert $page) => $page->where('user.can.erase', true)->where('user.erase_mode', 'deleted'));
        $this->actingAs($this->owner)->get(route('admin.users.show', $author))
            ->assertInertia(fn (Assert $page) => $page->where('user.erase_mode', 'anonymised'));
    }

    public function test_public_account_is_deleted_with_its_mailing_contact(): void
    {
        $reader = User::factory()->withContactDetails()->create();
        $contact = MailingContact::query()->create(['email' => $reader->email, 'user_id' => $reader->id]);

        $this->erase($reader)->assertRedirect(route('admin.users.index'))->assertSessionHas('status', 'User deleted.');

        $this->assertDatabaseMissing('users', ['id' => $reader->id]);
        $this->assertDatabaseMissing('mailing_contacts', ['id' => $contact->id]);
        $log = AuditLog::query()->where('action', 'user.erased')->sole();
        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame(['reason' => 'GDPR erasure request', 'mode' => 'deleted', 'user_id' => $reader->id], $log->payload);
    }

    public function test_author_is_anonymised_in_place_and_keeps_attribution(): void
    {
        $author = User::factory()->staff()->withContactDetails()->create(['name' => 'Ann Author']);
        $post = Post::factory()->create(['author_id' => $author->id]);
        Profile::query()->create(['user_id' => $author->id, 'display_name' => 'Ann']);
        ApiToken::issue($author, 'cli');
        $oldEmail = $author->email;

        $this->erase($author)->assertSessionHas('status', fn (string $status) => str_contains($status, 'anonymised'));

        $author->refresh();
        $this->assertSame(UserErasureService::ANONYMISED_NAME, $author->name);
        $this->assertSame("erased-{$author->id}@users.invalid", $author->email);
        $this->assertSame(Role::Public, $author->role);
        $this->assertNull($author->external_id);
        $this->assertNull($author->auth_provider);
        $this->assertNull($author->password);
        $this->assertTrue($author->isLocked());
        $this->assertSame(array_values(User::clearedContactDetails()), array_values(array_intersect_key(
            $author->contactDetails(),
            User::clearedContactDetails(),
        )));
        $this->assertNull($author->profile()->first());
        $this->assertSame(0, $author->apiTokens()->count());
        $this->assertSame($author->id, $post->fresh()->author_id);
        $this->assertDatabaseMissing('users', ['email' => $oldEmail]);
        $this->assertSame('anonymised', AuditLog::query()->where('action', 'user.erased')->sole()->payload['mode']);
    }

    public function test_confirmation_must_match_the_users_email(): void
    {
        $reader = User::factory()->create();

        $this->erase($reader, 'wrong@example.test')->assertSessionHasErrors('confirm_email');
        $this->assertDatabaseHas('users', ['id' => $reader->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.erased']);
    }

    public function test_only_super_admins_erase_and_never_themselves_or_another_owner(): void
    {
        $reader = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $otherOwner = User::factory()->superAdmin()->create();

        $this->actingAs($this->owner)
            ->delete(route('admin.users.destroy', $reader), ['reason' => 'No re-auth', 'confirm_email' => $reader->email])
            ->assertRedirect(route('account.confirm-identity'));
        $this->actingAs($admin)->withRecentAuthentication()
            ->delete(route('admin.users.destroy', $reader), ['reason' => 'Nope', 'confirm_email' => $reader->email])
            ->assertForbidden();
        $this->erase($this->owner)->assertForbidden();
        $this->erase($otherOwner)->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $reader->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.erased']);
    }

    private function erase(User $user, ?string $confirmEmail = null)
    {
        return $this->actingAs($this->owner)->withRecentAuthentication()
            ->from(route('admin.users.show', $user))
            ->delete(route('admin.users.destroy', $user), [
                'reason' => 'GDPR erasure request',
                'confirm_email' => $confirmEmail ?? $user->email,
            ]);
    }
}
