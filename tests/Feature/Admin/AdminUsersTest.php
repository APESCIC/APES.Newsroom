<?php

namespace Tests\Feature\Admin;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Auth\TwoFactor\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CreatesPasskeys;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use CreatesPasskeys, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_only_admins_and_super_admins_can_open_user_management(): void
    {
        $target = User::factory()->create();

        $this->get('/admin/users')->assertRedirect('/login');
        $this->get("/admin/users/{$target->id}")->assertRedirect('/login');

        foreach ([User::factory()->create(), User::factory()->staff()->create()] as $user) {
            $this->actingAs($user)->get('/admin/users')->assertForbidden();
            $this->actingAs($user)->get("/admin/users/{$target->id}")->assertForbidden();
        }

        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)->get('/admin/users')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Index'));
            $this->actingAs($user)->get("/admin/users/{$target->id}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('Admin/Users/Show')->where('user.id', $target->id));
        }
    }

    public function test_list_searches_and_filters(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada@test.invalid']);
        User::factory()->create(['name' => 'Paula Password', 'email' => 'paula@example.com']);
        User::factory()->create(['name' => 'Mia Magic', 'email' => 'mia@test.invalid', 'password' => null]);
        User::factory()->unverified()->create(['name' => 'Una Unverified', 'email' => 'una@test.invalid']);
        User::factory()->staff()->create(['name' => 'Sam Staff', 'email' => 'sam@test.invalid']);
        $enrolled = User::factory()->create(['name' => 'Tom Totp', 'email' => 'tom@test.invalid']);
        $enrolled->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $passkeyUser = User::factory()->create(['name' => 'Pat Passkey', 'email' => 'pat@test.invalid']);
        $this->createPasskey($passkeyUser);

        $cases = [
            'q=paula' => ['Paula Password'],
            'q=example.com' => null,
            'role=staff' => ['Sam Staff'],
            'role=admin' => ['Ada Admin'],
            'method=sso' => ['Ada Admin', 'Sam Staff'],
            'method=passwordless' => ['Mia Magic'],
            'status=unverified' => ['Una Unverified'],
            'security=two_factor' => ['Tom Totp'],
            'security=passkey' => ['Pat Passkey'],
        ];

        foreach ($cases as $query => $names) {
            $this->actingAs($admin)->get("/admin/users?{$query}")
                ->assertOk()
                ->assertInertia(function (Assert $page) use ($names, $query) {
                    if ($names !== null) {
                        $page->where('users.data', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === $names, "Filter {$query}");
                    }
                });
        }

        $this->actingAs($admin)->get('/admin/users?security=none')
            ->assertInertia(fn (Assert $page) => $page->where('users.data', fn ($rows) => ! collect($rows)->pluck('name')->contains('Tom Totp')
                && ! collect($rows)->pluck('name')->contains('Pat Passkey')));

        $this->actingAs($admin)->get('/admin/users?role=nonsense')->assertSessionHasErrors('role');
    }

    public function test_list_is_paginated(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(30)->create();

        $this->actingAs($admin)->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page->where('users.total', 31)->has('users.data', 25)->where('users.last_page', 2));
    }

    public function test_detail_shows_sessions_tokens_role_source_and_security_events(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create(['ldap_group_snapshot' => ['newsroom-staff']]);
        UserSession::factory()->create(['user_id' => $staff->id, 'ip_address' => '198.51.100.7']);
        UserSession::factory()->revoked()->create(['user_id' => $staff->id]);
        ApiToken::issue($staff, 'ci');
        AuditLog::query()->create(['actor_id' => $staff->id, 'action' => 'account.password_changed', 'payload' => []]);
        AuditLog::query()->create(['actor_id' => $staff->id, 'action' => 'auth.login', 'payload' => []]);
        AuditLog::query()->create(['actor_id' => $admin->id, 'action' => 'staff.role_changed', 'subject_type' => $staff->getMorphClass(), 'subject_id' => $staff->id, 'payload' => ['to' => 'staff']]);

        $this->actingAs($admin)->get("/admin/users/{$staff->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('user.sign_in_method', 'sso')
                ->where('user.sso_managed', true)
                ->where('user.role_source', ['type' => 'directory', 'groups' => ['newsroom-staff']])
                ->has('user.sessions', 1)
                ->where('user.sessions.0.ip_address', '198.51.100.7')
                ->has('user.api_tokens', 1)
                ->where('user.api_tokens.0.name', 'ci')
                ->where('user.security_events', fn ($events) => collect($events)->pluck('action')->all() === ['staff.role_changed', 'account.password_changed']));
    }

    public function test_props_never_include_hashes_or_secrets(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $twoFactor = app(TwoFactorService::class);
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $codes = $twoFactor->replaceRecoveryCodes($user);
        $token = ApiToken::issue($user, 'reader-token');
        $user->refresh();

        foreach (["/admin/users/{$user->id}", '/admin/users'] as $url) {
            $response = $this->actingAs($admin)->get($url)->assertOk();
            $props = json_encode($response->viewData('page')['props']);

            foreach ([$user->password, $user->remember_token, 'JBSWY3DPEHPK3PXP', $token['token'], $token['model']->token_hash, ...$codes] as $secret) {
                $this->assertStringNotContainsString((string) $secret, $props, "{$url} leaked a secret.");
            }

            foreach (['"password":', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token_hash'] as $key) {
                $this->assertStringNotContainsString($key, $props, "{$url} exposed {$key}.");
            }
        }
    }
}
