<?php

namespace Tests\Feature\Api;

use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\Auth\LdapGroupLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AdminApiTokenManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_token_cannot_mint_more_tokens_through_the_api(): void
    {
        $admin = User::factory()->admin()->create();
        $token = ApiToken::issue($admin, 'bootstrap')['token'];

        $this->withToken($token)
            ->postJson('/api/admin/v1/tokens', ['name' => 'escalated'])
            ->assertNotFound();

        $this->assertSame(1, ApiToken::count());
    }

    public function test_list_shows_tokens_without_secrets(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'staffer@example.com']);
        $issued = ApiToken::issue($staff, 'ci');

        $this->artisan('newsroom:list-admin-api-tokens')
            ->expectsTable(
                ['ID', 'Name', 'Owner', 'Role', 'Last used', 'Created'],
                [[$issued['model']->id, 'ci', 'staffer@example.com', 'staff', 'never', $issued['model']->created_at->toDateTimeString()]],
            )
            ->doesntExpectOutputToContain($issued['token'])
            ->assertSuccessful();
    }

    public function test_list_can_filter_by_owner(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'staffer@example.com']);
        ApiToken::issue($staff, 'mine');
        ApiToken::issue(User::factory()->admin()->create(), 'theirs');

        $this->artisan('newsroom:list-admin-api-tokens', ['--user' => 'staffer@example.com'])
            ->expectsOutputToContain('mine')
            ->doesntExpectOutputToContain('theirs')
            ->assertSuccessful();
    }

    public function test_revoke_by_id_deletes_only_that_token(): void
    {
        $staff = User::factory()->staff()->create();
        $keep = ApiToken::issue($staff, 'keep')['model'];
        $revoke = ApiToken::issue($staff, 'revoke')['model'];

        $this->artisan('newsroom:revoke-admin-api-token', ['id' => $revoke->id, '--force' => true])
            ->expectsOutput('Revoked 1 Admin API token(s).')
            ->assertSuccessful();

        $this->assertModelExists($keep);
        $this->assertModelMissing($revoke);
    }

    public function test_a_revoked_token_no_longer_authenticates(): void
    {
        $staff = User::factory()->staff()->create();
        $issued = ApiToken::issue($staff, 'ci');

        $this->artisan('newsroom:revoke-admin-api-token', ['id' => $issued['model']->id, '--force' => true])->assertSuccessful();

        $this->withToken($issued['token'])->getJson('/api/admin/v1/posts')->assertUnauthorized();
    }

    public function test_revoke_by_name_and_user(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'staffer@example.com']);
        $other = User::factory()->staff()->create();
        ApiToken::issue($staff, 'ci');
        ApiToken::issue($staff, 'laptop');
        $othersCi = ApiToken::issue($other, 'ci')['model'];

        $this->artisan('newsroom:revoke-admin-api-token', ['--name' => 'ci', '--user' => 'staffer@example.com', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(['laptop'], $staff->apiTokens()->pluck('name')->all());
        $this->assertModelExists($othersCi);
    }

    public function test_revoke_by_user_removes_all_their_tokens(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'staffer@example.com']);
        ApiToken::issue($staff, 'one');
        ApiToken::issue($staff, 'two');

        $this->artisan('newsroom:revoke-admin-api-token', ['--user' => 'staffer@example.com', '--force' => true])
            ->expectsOutput('Revoked 2 Admin API token(s).')
            ->assertSuccessful();

        $this->assertSame(0, $staff->apiTokens()->count());
    }

    public function test_revoke_all_asks_for_confirmation(): void
    {
        ApiToken::issue(User::factory()->staff()->create(), 'one');
        ApiToken::issue(User::factory()->admin()->create(), 'two');

        $this->artisan('newsroom:revoke-admin-api-token', ['--all' => true])
            ->expectsConfirmation('Revoke 2 Admin API token(s)?', 'no')
            ->assertFailed();
        $this->assertSame(2, ApiToken::count());

        $this->artisan('newsroom:revoke-admin-api-token', ['--all' => true])
            ->expectsConfirmation('Revoke 2 Admin API token(s)?', 'yes')
            ->assertSuccessful();
        $this->assertSame(0, ApiToken::count());
    }

    public function test_revoke_requires_a_selector(): void
    {
        ApiToken::issue(User::factory()->staff()->create(), 'one');

        $this->artisan('newsroom:revoke-admin-api-token', ['--force' => true])->assertFailed();
        $this->artisan('newsroom:revoke-admin-api-token', ['id' => 1, '--all' => true, '--force' => true])->assertFailed();

        $this->assertSame(1, ApiToken::count());
    }

    public function test_demotion_below_staff_deletes_the_users_tokens(): void
    {
        $demoted = User::factory()->admin()->create(['email' => 'leaver@example.com']);
        $kept = User::factory()->staff()->create(['email' => 'stayer@example.com']);
        ApiToken::issue($demoted, 'ci');
        ApiToken::issue($kept, 'ci');

        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->with('leaver@example.com')->andReturn([]);
        $lookup->shouldReceive('groupsForEmail')->with('stayer@example.com')->andReturn(['newsroom-staff']);
        $this->app->instance(LdapGroupLookup::class, $lookup);

        $this->artisan('staff:reconcile-roles')->assertSuccessful();

        $this->assertSame(Role::Public, $demoted->fresh()->role);
        $this->assertSame(0, $demoted->apiTokens()->count());
        $this->assertSame(1, $kept->apiTokens()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.role_changed', 'subject_id' => $demoted->id]);
    }

    public function test_a_downgrade_that_stays_at_staff_keeps_tokens(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);
        ApiToken::issue($admin, 'ci');

        $lookup = Mockery::mock(LdapGroupLookup::class);
        $lookup->shouldReceive('groupsForEmail')->with('admin@example.com')->andReturn(['newsroom-staff']);
        $this->app->instance(LdapGroupLookup::class, $lookup);

        $this->artisan('staff:reconcile-roles')->assertSuccessful();

        $this->assertSame(Role::Staff, $admin->fresh()->role);
        $this->assertSame(1, $admin->apiTokens()->count());
    }
}
