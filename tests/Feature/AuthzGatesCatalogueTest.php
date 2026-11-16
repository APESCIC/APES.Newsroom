<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Policies\ApiTokenPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\ContentApiKeyPolicy;
use App\Policies\EmailTemplatePolicy;
use App\Policies\MailingContactPolicy;
use App\Policies\MediaAssetPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\NewsletterTemplatePolicy;
use App\Policies\PagePolicy;
use App\Policies\PostPolicy;
use App\Policies\ProfilePolicy;
use App\Policies\UserPolicy;
use App\Policies\WebhookEndpointPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Catalogue of named gates and where they are covered. Keeps #818's
 * "documented list … each has tests" acceptance checkable.
 */
class AuthzGatesCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Named Gate::define abilities and the Feature test that covers them.
     *
     * @var array<string, string>
     */
    private const GATES = [
        'access-staff-area' => AuthzMatrixTest::class,
        'access-admin-area' => AuthzMatrixTest::class,
        'manage-newsletter-templates' => EmailTemplatePermissionsTest::class,
    ];

    /**
     * Policy classes and the Feature test that covers their abilities.
     * Route-level coverage lives in AuthzMatrixTest; UserPolicy has a
     * dedicated ability matrix.
     *
     * @var array<class-string, string>
     */
    private const POLICIES = [
        ApiTokenPolicy::class => AuthzMatrixTest::class,
        AuditLogPolicy::class => AuthzMatrixTest::class,
        ContentApiKeyPolicy::class => AuthzMatrixTest::class,
        EmailTemplatePolicy::class => EmailTemplatePermissionsTest::class,
        MailingContactPolicy::class => AuthzMatrixTest::class,
        MediaAssetPolicy::class => AuthzMatrixTest::class,
        MembershipPolicy::class => AuthzMatrixTest::class,
        NewsletterTemplatePolicy::class => EmailTemplatePermissionsTest::class,
        PagePolicy::class => AuthzMatrixTest::class,
        PostPolicy::class => AuthzMatrixTest::class,
        ProfilePolicy::class => AuthzMatrixTest::class,
        UserPolicy::class => Auth\UserPolicyTest::class,
        WebhookEndpointPolicy::class => AuthzMatrixTest::class,
    ];

    public function test_every_named_gate_is_registered_and_catalogued(): void
    {
        foreach (self::GATES as $ability => $testClass) {
            $this->assertTrue(Gate::has($ability), "Gate [{$ability}] is not registered.");
            $this->assertTrue(class_exists($testClass), "Covering test [{$testClass}] for gate [{$ability}] is missing.");
        }
    }

    public function test_every_policy_class_is_catalogued(): void
    {
        $discovered = collect(glob(app_path('Policies/*.php')))
            ->map(fn (string $path) => 'App\\Policies\\'.basename($path, '.php'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            array_keys(self::POLICIES),
            $discovered,
            'app/Policies and the catalogue have drifted; update AuthzGatesCatalogueTest::POLICIES.',
        );

        foreach (self::POLICIES as $policy => $testClass) {
            $this->assertTrue(class_exists($policy), "Policy [{$policy}] is missing.");
            $this->assertTrue(class_exists($testClass), "Covering test [{$testClass}] for [{$policy}] is missing.");
        }
    }

    public function test_staff_forbidden_on_admin_uses_panel_403_page(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get('/admin/permissions')
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page
                ->component('Errors/Panel')
                ->where('status', 403)
                ->where('area', 'Staff')
                ->where('requiredRole', 'Admin'));
    }

    public function test_public_forbidden_on_staff_uses_public_403_page(): void
    {
        $public = User::factory()->create(['role' => Role::Public]);

        $this->actingAs($public)
            ->get('/staff')
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page
                ->component('Errors/Show')
                ->where('status', 403)
                ->where('title', 'You do not have access'));
    }

    public function test_guest_is_redirected_not_server_error(): void
    {
        $this->get('/admin/permissions')
            ->assertRedirect('/login')
            ->assertStatus(302);
    }
}
