<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\ContentApiKey;
use App\Models\ImportRun;
use App\Models\MediaAsset;
use App\Models\MembershipPlan;
use App\Models\ModerationReport;
use App\Models\Newsletter;
use App\Models\Offer;
use App\Models\Page;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Profile;
use App\Models\Release;
use App\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthzMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Minimum role for every named staff and admin route. Staff routes that
     * act on content are exercised with content the acting user authored.
     *
     * @var array<string, Role>
     */
    private const MATRIX = [
        'admin.audit-log.index' => Role::Admin,
        'admin.audit-log.export' => Role::Admin,
        'admin.api-tokens.index' => Role::Admin,
        'admin.api-tokens.store' => Role::Admin,
        'admin.api-tokens.destroy' => Role::Admin,
        'admin.content-api-keys.index' => Role::Admin,
        'admin.content-api-keys.store' => Role::Admin,
        'admin.content-api-keys.destroy' => Role::Admin,
        'admin.imports.ghost-content' => Role::Staff,
        'admin.imports.ghost-content.upload' => Role::Staff,
        'admin.imports.ghost-content.confirm' => Role::Staff,
        'admin.imports.ghost-content.report' => Role::Staff,
        'admin.imports.ghost-members' => Role::Admin,
        'admin.imports.ghost-members.upload' => Role::Admin,
        'admin.imports.ghost-members.report' => Role::Admin,
        'admin.members.index' => Role::Admin,
        'admin.users.index' => Role::Admin,
        'admin.users.show' => Role::Admin,
        'admin.users.lock' => Role::Admin,
        'admin.users.unlock' => Role::Admin,
        'admin.users.sign-out' => Role::Admin,
        'admin.users.password-reset' => Role::Admin,
        'admin.users.two-factor.reset' => Role::Admin,
        'admin.users.passkeys.remove' => Role::Admin,
        'admin.users.role' => Role::SuperAdmin,
        'admin.moderation.index' => Role::Admin,
        'admin.moderation.comments' => Role::Admin,
        'admin.moderation.comments.restore' => Role::Admin,
        'admin.moderation.profiles' => Role::Admin,
        'admin.moderation.reports' => Role::Admin,
        'admin.releases.index' => Role::Admin,
        'admin.releases.store' => Role::Admin,
        'admin.releases.create' => Role::Admin,
        'admin.releases.update' => Role::Admin,
        'admin.releases.destroy' => Role::Admin,
        'admin.releases.edit' => Role::Admin,
        'admin.settings.edit' => Role::Admin,
        'admin.settings.update' => Role::Admin,
        'admin.settings.integrations.index' => Role::Admin,
        'admin.settings.integrations.test' => Role::Admin,
        'admin.settings.integrations.update' => Role::SuperAdmin,
        'admin.settings.integrations.destroy' => Role::SuperAdmin,
        'admin.webhooks.index' => Role::Admin,
        'admin.webhooks.store' => Role::Admin,
        'admin.webhooks.update' => Role::Admin,
        'admin.webhooks.destroy' => Role::Admin,
        'staff.ai.status' => Role::Staff,
        'staff.ai.suggest' => Role::Staff,
        'staff.media.by-url' => Role::Staff,
        'staff.media.hero.upload' => Role::Staff,
        'staff.media.link-meta' => Role::Staff,
        'staff.media.show' => Role::Staff,
        'staff.media.unsplash.search' => Role::Staff,
        'staff.media.unsplash.select' => Role::Staff,
        'staff.media.upload' => Role::Staff,
        'staff.members.legacy' => Role::Staff,
        'staff.webhooks.legacy' => Role::Staff,
        'staff.membership-plans.index' => Role::Staff,
        'staff.membership-plans.update' => Role::Staff,
        'staff.metrics.index' => Role::Staff,
        'staff.newsletters.index' => Role::Staff,
        'staff.newsletters.store' => Role::Staff,
        'staff.newsletters.create' => Role::Staff,
        'staff.newsletters.update' => Role::Staff,
        'staff.newsletters.archive' => Role::Staff,
        'staff.newsletters.edit' => Role::Staff,
        'staff.newsletters.restore' => Role::Staff,
        'staff.newsletters.segments.store' => Role::Staff,
        'staff.newsletters.verify-sender' => Role::Staff,
        'staff.offers.index' => Role::Staff,
        'staff.offers.store' => Role::Staff,
        'staff.offers.update' => Role::Staff,
        'staff.pages.index' => Role::Staff,
        'staff.pages.store' => Role::Staff,
        'staff.pages.create' => Role::Staff,
        'staff.pages.update' => Role::Staff,
        'staff.pages.destroy' => Role::Staff,
        'staff.pages.edit' => Role::Staff,
        'staff.pages.preview' => Role::Staff,
        'staff.pages.publish' => Role::Staff,
        'staff.pages.unpublish' => Role::Staff,
        'staff.posts.index' => Role::Staff,
        'staff.posts.store' => Role::Staff,
        'staff.posts.create' => Role::Staff,
        'staff.posts.review' => Role::Admin,
        'staff.posts.update' => Role::Staff,
        'staff.posts.destroy' => Role::Staff,
        'staff.posts.campaign.preview' => Role::Admin,
        'staff.posts.campaign.test' => Role::Admin,
        'staff.posts.edit' => Role::Staff,
        'staff.posts.preview' => Role::Staff,
        'staff.posts.publish' => Role::Admin,
        'staff.posts.reject' => Role::Admin,
        'staff.posts.revisions.restore' => Role::Staff,
        'staff.posts.schedule' => Role::Admin,
        'staff.posts.submit' => Role::Staff,
        'staff.posts.unpublish' => Role::Admin,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    /**
     * @return array<string, array{0: string, 1: Role}>
     */
    public static function matrix(): array
    {
        $cases = [];

        foreach (self::MATRIX as $name => $minimum) {
            $cases[$name] = [$name, $minimum];
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_route_is_limited_to_its_minimum_role(string $name, Role $minimum): void
    {
        $method = $this->methodFor($name);

        $this->call($method, $this->urlFor($name, User::factory()->create()))
            ->assertRedirect('/login');

        foreach (Role::cases() as $role) {
            $user = $this->userWithRole($role);
            $response = $this->actingAs($user)->withRecentAuthentication()->call($method, $this->urlFor($name, $user));

            if (! $role->atLeast($minimum)) {
                $response->assertForbidden();

                continue;
            }

            $status = $response->getStatusCode();
            $this->assertNotContains($status, [401, 403], "{$role->value} should reach {$name}, got {$status}.");
            $this->assertTrue($status < 500 || $status === 503, "{$role->value} on {$name} returned {$status}.");

            $location = (string) $response->headers->get('Location');
            $this->assertStringNotContainsString('/login', $location, "{$role->value} was sent to sign in from {$name}.");
            $this->assertStringNotContainsString('confirm-identity', $location, "{$role->value} was asked to re-authenticate on {$name}.");
        }
    }

    public function test_every_staff_and_admin_route_is_in_the_matrix(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn (?string $name) => $name !== null && Str::startsWith($name, ['staff.', 'admin.']))
            ->values();

        $missing = $names->diff(array_keys(self::MATRIX))->values()->all();
        $stale = collect(array_keys(self::MATRIX))->diff($names)->values()->all();

        $this->assertSame([], $missing, 'Add these routes to AuthzMatrixTest::MATRIX.');
        $this->assertSame([], $stale, 'Remove these routes from AuthzMatrixTest::MATRIX.');
    }

    public function test_public_cannot_access_staff_or_admin(): void
    {
        $public = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($public)->withRecentAuthentication()->get('/staff/posts')->assertForbidden();
        $this->actingAs($public)->withRecentAuthentication()->get('/admin/moderation')->assertForbidden();
        $this->actingAs($public)->withRecentAuthentication()->post("/staff/posts/{$post->id}/publish")->assertForbidden();
    }

    public function test_staff_can_edit_own_draft_but_not_publish(): void
    {
        $staff = User::factory()->staff()->create();
        $own = Post::factory()->create(['author_id' => $staff->id]);
        $other = Post::factory()->create();

        $this->actingAs($staff)->withRecentAuthentication()->get("/staff/posts/{$own->id}/edit")->assertOk();
        $this->actingAs($staff)->withRecentAuthentication()->get("/staff/posts/{$other->id}/edit")->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()->post("/staff/posts/{$own->id}/publish")->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()->get('/admin/moderation')->assertForbidden();
    }

    public function test_staff_cannot_edit_or_delete_another_authors_page(): void
    {
        $staff = User::factory()->staff()->create();
        $other = Page::factory()->create();

        $this->actingAs($staff)->withRecentAuthentication()->get("/staff/pages/{$other->id}/edit")->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()->delete("/staff/pages/{$other->id}")->assertForbidden();
        $this->assertNotSoftDeleted($other);
    }

    public function test_staff_cannot_reach_member_crm_or_webhooks(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->withRecentAuthentication()->get('/admin/members')->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()->get('/admin/webhooks')->assertForbidden();
        $this->actingAs($staff)->withRecentAuthentication()->get('/staff/members')->assertRedirect('/admin/members');
    }

    public function test_guest_is_redirected_from_admin_settings(): void
    {
        $this->get('/admin/settings')->assertRedirect('/login');
        $this->put('/admin/settings', ['settings' => ['editor' => ['driver' => 'tinymce']]])->assertRedirect('/login');
    }

    public function test_public_and_staff_cannot_access_admin_settings(): void
    {
        foreach ([User::factory()->create(), User::factory()->staff()->create()] as $user) {
            $this->actingAs($user)->withRecentAuthentication()->get('/admin/settings')->assertForbidden();
            $this->actingAs($user)->withRecentAuthentication()
                ->put('/admin/settings', ['settings' => ['editor' => ['driver' => 'tinymce']]])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_admin_and_super_admin_can_access_admin_settings(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)->withRecentAuthentication()->get('/admin/settings')->assertOk();
            $this->actingAs($user)->withRecentAuthentication()
                ->put('/admin/settings', ['settings' => ['editor' => ['driver' => 'tinymce']]])
                ->assertRedirect('/admin/settings');
        }
    }

    public function test_admin_publish_writes_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $post = Post::factory()->create([
            'author_id' => $admin->id,
            'status' => PostStatus::InReview,
        ]);

        $this->actingAs($admin)->withRecentAuthentication()->post("/staff/posts/{$post->id}/publish")->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'post.published',
            'subject_id' => $post->id,
        ]);
        $this->assertInstanceOf(AuditLog::class, AuditLog::query()->where('action', 'post.published')->first());
    }

    public function test_security_headers_are_present(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_csp_allows_self_hosted_tinymce_without_third_party_origins(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("img-src 'self' data: blob: https:;", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline';", $csp);
        $this->assertStringContainsString("connect-src 'self';", $csp);
        $this->assertStringNotContainsString('tiny.cloud', $csp);
        $this->assertStringNotContainsString('tinymce.com', $csp);
    }

    private function userWithRole(Role $role): User
    {
        $factory = User::factory();

        return match ($role) {
            Role::Public => $factory->create(),
            Role::Staff => $factory->staff()->create(),
            Role::Admin => $factory->admin()->create(),
            Role::SuperAdmin => $factory->superAdmin()->create(),
        };
    }

    private function methodFor(string $name): string
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, "Route {$name} does not exist.");

        return $route->methods()[0];
    }

    private function urlFor(string $name, User $user): string
    {
        $route = Route::getRoutes()->getByName($name);
        $parameters = [];
        $post = null;

        foreach ($route->parameterNames() as $parameter) {
            $parameters[$parameter] = match ($parameter) {
                'post' => $post = Post::factory()->create(['author_id' => $user->id]),
                'revision' => PostRevision::query()->create([
                    'post_id' => $post->id,
                    'editor_id' => $user->id,
                    'content' => ['blocks' => []],
                    'title' => 'Revision',
                ]),
                'page' => Page::factory()->create(['author_id' => $user->id]),
                'user' => User::factory()->create(),
                'apiToken' => ApiToken::issue(User::factory()->staff()->create(), 'matrix')['model'],
                'contentApiKey' => ContentApiKey::issue('matrix', null)['model'],
                'release' => Release::factory()->create(['slug' => 'release-'.Str::lower(Str::random(12))]),
                'webhook' => WebhookEndpoint::query()->create([
                    'name' => 'Partner',
                    'url' => 'https://hooks.example.com',
                    'secret' => WebhookEndpoint::generateSecret(),
                    'events' => ['post.published'],
                    'is_active' => true,
                ]),
                'run' => ImportRun::query()->create([
                    'type' => Str::contains($name, 'ghost-members') ? 'ghost_members_csv' : 'ghost_content',
                    'status' => 'completed',
                    'dry_run' => true,
                    'actor_id' => $user->id,
                    'report' => [],
                ]),
                'comment' => $this->comment(),
                'profile' => Profile::query()->create([
                    'user_id' => User::factory()->create()->id,
                    'display_name' => 'Reader',
                ]),
                'report' => ModerationReport::query()->create([
                    'reporter_id' => User::factory()->create()->id,
                    'reportable_type' => Post::class,
                    'reportable_id' => Post::factory()->create()->id,
                    'reason' => 'Report',
                ]),
                'plan' => MembershipPlan::query()->firstOrFail(),
                'asset' => MediaAsset::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'disk' => 'public',
                    'path' => 'media/matrix/original.jpg',
                    'mime' => 'image/jpeg',
                    'width' => 10,
                    'height' => 10,
                    'bytes' => 100,
                    'sha256' => hash('sha256', Str::random(16)),
                ]),
                'provider' => 'unsplash',
                'key' => 'access_key',
                'newsletter' => Newsletter::query()->create([
                    'name' => 'Newsletter',
                    'slug' => 'newsletter-'.Str::random(8),
                ]),
                'offer' => Offer::query()->create([
                    'code' => 'CODE'.Str::upper(Str::random(6)),
                    'name' => 'Offer',
                    'discount_type' => 'percent',
                    'discount_value' => 10,
                ]),
            };
        }

        return route($name, $parameters, false);
    }

    private function comment(): Comment
    {
        return Comment::query()->create([
            'post_id' => Post::factory()->published()->create()->id,
            'user_id' => User::factory()->create()->id,
            'body' => 'A comment',
            'body_hash' => hash('sha256', 'A comment'.Str::random(8)),
        ]);
    }
}
