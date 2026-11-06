<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Services\Auth\RecentAuthentication;
use App\Services\EmailTemplates\EmailTemplateService;
use App\Support\EmailTemplates\TemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmailTemplatePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::get('/__test/admin/settings/email-templates', function () {
                Gate::authorize('access-admin-area');

                return response('ok');
            })->middleware(['auth', 'verified'])->name('__test.email-templates.index');
        });
    }

    #[Test]
    public function staff_cannot_view_email_template_admin_area(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)
            ->get('/__test/admin/settings/email-templates')
            ->assertForbidden();
    }

    #[Test]
    public function admin_can_view_email_template_admin_area(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/__test/admin/settings/email-templates')
            ->assertOk()
            ->assertSee('ok');
    }

    #[Test]
    public function security_template_edits_require_recent_authentication(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $this->startSessionOnRequest();

        $definition = app(TemplateRegistry::class)->get('password_changed');
        $service = app(EmailTemplateService::class);

        try {
            $service->assertCanEdit($admin, $definition);
            $this->fail('Expected 423 without recent authentication.');
        } catch (HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
        }

        $nonSecurity = app(TemplateRegistry::class)->get('magic_link');
        $service->assertCanEdit($admin, $nonSecurity);

        app(RecentAuthentication::class)->stamp(request());
        $service->assertCanEdit($admin, $definition);
    }

    #[Test]
    public function staff_cannot_pass_assert_can_edit(): void
    {
        $staff = User::factory()->staff()->create(['role' => Role::Staff]);
        $this->actingAs($staff);
        $this->startSessionOnRequest();
        $definition = app(TemplateRegistry::class)->get('magic_link');

        try {
            app(EmailTemplateService::class)->assertCanEdit($staff, $definition);
            $this->fail('Expected 403 for staff.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function startSessionOnRequest(): void
    {
        $session = $this->app['session']->driver();
        $session->start();
        request()->setLaravelSession($session);
    }
}
