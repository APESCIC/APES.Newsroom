<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Enums\ReactionType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Comment;
use App\Models\ImportRun;
use App\Models\ModerationAudit;
use App\Models\ModerationReport;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Profile;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_account_page_exposes_server_derived_deletion_eligibility(): void
    {
        $publicUser = User::factory()->create();

        $this->actingAs($publicUser)
            ->get(route('account.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Account/Profile')
                ->where('can_delete_account', true)
                ->where('deletion_block_reason', null)
                ->where('membership.status', 'free'));

        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get(route('account.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_delete_account', false)
                ->where('deletion_block_reason', fn (?string $reason) => str_contains((string) $reason, 'administrator')));
    }

    public function test_user_can_update_profile(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->patch(route('account.update'), ['name' => 'New Name', 'email' => $user->email])
            ->assertRedirect();

        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_user_can_export_account_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('account.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/json');
        $this->assertStringContainsString($user->email, $response->streamedContent());
    }

    public function test_user_can_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete(route('account.destroy'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_privileged_and_oidc_accounts_cannot_call_self_service_deletion_directly(): void
    {
        $accounts = collect([Role::Staff, Role::Admin, Role::SuperAdmin])
            ->flatMap(fn (Role $role) => [
                User::factory()->create([
                    'role' => $role,
                    'auth_provider' => 'password',
                    'external_id' => null,
                ]),
                User::factory()->create([
                    'role' => $role,
                    'auth_provider' => 'cloudron_oidc',
                    'password' => null,
                    'external_id' => fake()->uuid(),
                ]),
            ])
            ->push(User::factory()->create([
                'role' => Role::Public,
                'auth_provider' => 'cloudron_oidc',
                'password' => null,
                'external_id' => fake()->uuid(),
            ]));

        foreach ($accounts as $account) {
            $this->actingAs($account)
                ->from(route('account.show'))
                ->delete(route('account.destroy'))
                ->assertRedirect(route('account.show'))
                ->assertSessionHasErrors('delete_account');

            $this->assertAuthenticatedAs($account);
            $this->assertDatabaseHas('users', ['id' => $account->id]);
        }
    }

    public function test_demoted_public_author_is_blocked_without_deleting_editorial_records(): void
    {
        $user = User::factory()->create([
            'role' => Role::Public,
            'auth_provider' => 'password',
        ]);
        $post = Post::factory()->create(['author_id' => $user->id]);

        $this->actingAs($user)
            ->from(route('account.show'))
            ->delete(route('account.destroy'))
            ->assertRedirect(route('account.show'))
            ->assertSessionHasErrors('delete_account');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_database_restricts_each_editorial_attribution_independently(): void
    {
        $records = [
            'posts' => fn (User $user) => Post::factory()->create(['author_id' => $user->id]),
            'post_revisions' => function (User $user) {
                $post = Post::factory()->create();

                return PostRevision::query()->create([
                    'post_id' => $post->id,
                    'editor_id' => $user->id,
                    'content' => ['blocks' => []],
                    'title' => 'Protected revision',
                ]);
            },
            'campaigns' => function (User $user) {
                $post = Post::factory()->create();

                return Campaign::query()->create([
                    'post_id' => $post->id,
                    'created_by' => $user->id,
                    'lists' => ['newsroom'],
                    'snapshot' => ['subject' => 'Protected campaign'],
                    'status' => CampaignStatus::Draft,
                ]);
            },
        ];

        foreach ($records as $table => $createRecord) {
            $user = User::factory()->create();
            $record = $createRecord($user);

            try {
                $user->delete();
                $this->fail("The database removed the protected attribution from {$table}.");
            } catch (QueryException) {
                $this->assertDatabaseHas('users', ['id' => $user->id]);
                $this->assertDatabaseHas($table, ['id' => $record->id]);
            }
        }
    }

    public function test_eligible_public_deletion_removes_public_participation_records(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();
        $profile = Profile::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Reader profile',
        ]);
        $comment = Comment::query()->create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'body' => 'A public comment',
            'body_hash' => hash('sha256', 'A public comment'),
        ]);
        $reaction = Reaction::query()->create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'type' => ReactionType::Helpful,
        ]);
        $report = ModerationReport::query()->create([
            'reporter_id' => $user->id,
            'reportable_type' => Post::class,
            'reportable_id' => $post->id,
            'reason' => 'A public report',
        ]);

        $this->actingAs($user)
            ->delete(route('account.destroy'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
        $this->assertDatabaseMissing('reactions', ['id' => $reaction->id]);
        $this->assertDatabaseMissing('moderation_reports', ['id' => $report->id]);
        $this->assertDatabaseHas('posts', ['id' => $post->id]);
    }

    public function test_database_preserves_audit_actor_attribution(): void
    {
        $records = [
            'audit_logs' => fn (User $user) => AuditLog::query()->create([
                'actor_id' => $user->id,
                'action' => 'account.test',
            ]),
            'moderation_audits' => fn (User $user) => ModerationAudit::query()->create([
                'actor_id' => $user->id,
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'action' => 'account.test',
            ]),
            'import_runs' => fn (User $user) => ImportRun::query()->create([
                'actor_id' => $user->id,
                'type' => 'account-test',
                'status' => 'completed',
                'dry_run' => true,
            ]),
        ];

        foreach ($records as $table => $createRecord) {
            $user = User::factory()->create();
            $record = $createRecord($user);

            try {
                $user->delete();
                $this->fail("The database removed the actor for {$table}.");
            } catch (QueryException) {
                $this->assertDatabaseHas('users', ['id' => $user->id]);
                $this->assertDatabaseHas($table, [
                    'id' => $record->id,
                    'actor_id' => $user->id,
                ]);
            }
        }
    }
}
