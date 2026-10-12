<?php

namespace Tests\Feature\Account;

use App\Http\Controllers\Account\PasskeyController;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Security\PasskeyAddedNotification;
use App\Notifications\Security\PasskeyRemovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CreatesPasskeys;
use Tests\Support\FakeAuthenticator;
use Tests\TestCase;

class PasskeyManagementTest extends TestCase
{
    use CreatesPasskeys, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_registration_options_need_recent_authentication_and_remember_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('account.security'))
            ->postJson(route('account.passkeys.options'))
            ->assertStatus(423);

        $this->assertSame(route('account.security'), session('url.intended'));
    }

    public function test_a_member_can_add_a_passkey(): void
    {
        $user = User::factory()->create();
        $authenticator = $this->fakeAuthenticator();

        $options = $this->actingAs($user)
            ->withRecentAuthentication()
            ->postJson(route('account.passkeys.options'))
            ->assertOk()
            ->assertJsonPath('rp.id', 'localhost')
            ->getContent();

        $this->post(route('account.passkeys.store'), [
            'name' => '  Work laptop ',
            'credential' => $authenticator->register($options),
        ])
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'passkey-added');

        $passkey = $user->passkeys()->sole();
        $this->assertSame('Work laptop', $passkey->name);

        $audit = AuditLog::query()->where('action', 'account.passkey_added')->sole();
        $this->assertSame(['passkey_id' => $passkey->id, 'name' => 'Work laptop'], $audit->payload);

        Notification::assertSentTo($user, PasskeyAddedNotification::class, fn ($n) => $n->passkeyName === 'Work laptop');
    }

    public function test_an_invalid_credential_is_refused_without_a_notification(): void
    {
        $user = User::factory()->create();

        $options = $this->actingAs($user)->withRecentAuthentication()->postJson(route('account.passkeys.options'))->getContent();

        $this->post(route('account.passkeys.store'), [
            'name' => 'Phone',
            'credential' => (new FakeAuthenticator('https://evil.example'))->register($options),
        ])->assertSessionHasErrors('passkey');

        $this->assertSame(0, $user->passkeys()->count());
        Notification::assertNothingSent();
    }

    public function test_adding_a_passkey_needs_options_from_this_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withRecentAuthentication()
            ->post(route('account.passkeys.store'), [
                'name' => 'Phone',
                'credential' => '{"id":"x"}',
            ])
            ->assertSessionHasErrors('passkey');
    }

    public function test_adding_a_passkey_needs_recent_authentication(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('account.passkeys.store'), ['name' => 'Phone', 'credential' => '{}'])
            ->assertRedirect(route('account.confirm-identity'));
    }

    public function test_the_number_of_passkeys_is_capped(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < PasskeyController::MAX_PASSKEYS; $i++) {
            $this->createPasskey($user, "Key {$i}");
        }

        $this->actingAs($user)
            ->withRecentAuthentication()
            ->postJson(route('account.passkeys.options'))
            ->assertStatus(422);
    }

    public function test_sso_accounts_cannot_manage_passkeys(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->withRecentAuthentication()
            ->postJson(route('account.passkeys.options'))
            ->assertForbidden();

        $this->post(route('account.passkeys.store'), ['name' => 'Phone', 'credential' => '{}'])
            ->assertForbidden();

        $this->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page->where('passkeys', null));
    }

    public function test_the_security_page_lists_passkeys(): void
    {
        $user = User::factory()->create();
        $this->createPasskey($user, 'Phone');
        $this->createPasskey($user, 'Security key');

        $this->actingAs($user)
            ->get(route('account.security'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Security')
                ->has('passkeys', 2)
                ->where('passkeys.0.name', 'Phone')
                ->where('passkeys.1.name', 'Security key')
                ->where('passkeys.0.last_used_at', null)
                ->missing('passkeys.0.credential_id')
                ->missing('passkeys.0.data'));
    }

    public function test_a_member_can_rename_a_passkey(): void
    {
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user, 'Phone');

        $this->actingAs($user)
            ->patch(route('account.passkeys.update', $passkey->id), ['name' => 'Old phone'])
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'passkey-renamed');

        $this->assertSame('Old phone', $passkey->fresh()->name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.passkey_renamed', 'actor_id' => $user->id]);
    }

    public function test_removing_a_passkey_needs_recent_authentication(): void
    {
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user);

        $this->actingAs($user)
            ->delete(route('account.passkeys.destroy', $passkey->id))
            ->assertRedirect(route('account.confirm-identity'));

        $this->assertNotNull($passkey->fresh());
    }

    public function test_a_member_can_remove_a_passkey(): void
    {
        $user = User::factory()->create();
        $passkey = $this->createPasskey($user, 'Phone');

        $this->actingAs($user)
            ->withRecentAuthentication()
            ->delete(route('account.passkeys.destroy', $passkey->id))
            ->assertRedirect(route('account.security'))
            ->assertSessionHas('status', 'passkey-removed');

        $this->assertNull($passkey->fresh());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.passkey_removed', 'actor_id' => $user->id]);
        Notification::assertSentTo($user, PasskeyRemovedNotification::class, fn ($n) => $n->passkeyName === 'Phone');
    }

    public function test_members_cannot_touch_someone_elses_passkeys(): void
    {
        $owner = User::factory()->create();
        $passkey = $this->createPasskey($owner, 'Phone');
        $other = User::factory()->create();

        $this->actingAs($other)
            ->withRecentAuthentication()
            ->patch(route('account.passkeys.update', $passkey->id), ['name' => 'Mine now'])
            ->assertNotFound();

        $this->delete(route('account.passkeys.destroy', $passkey->id))->assertNotFound();

        $this->assertSame('Phone', $passkey->fresh()->name);
        Notification::assertNothingSent();
    }

    public function test_the_account_export_lists_passkeys_and_two_factor_status(): void
    {
        $user = User::factory()->create();
        $this->createPasskey($user, 'Phone');

        $export = json_decode(
            $this->actingAs($user)->withRecentAuthentication()->get(route('account.export'))->streamedContent(),
            true,
        );

        $this->assertFalse($export['security']['two_factor_enabled']);
        $this->assertSame('Phone', $export['security']['passkeys'][0]['name']);
        $this->assertArrayNotHasKey('credential_id', $export['security']['passkeys'][0]);
    }
}
