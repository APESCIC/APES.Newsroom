<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PanelErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_unknown_staff_url_renders_a_404_inside_the_staff_panel(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/staff/no-such-page/deeper')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Errors/Panel')
                ->where('status', 404)
                ->where('area', 'Staff')
                ->where('home', '/staff')
                ->has('auth.can.accessStaff'));
    }

    public function test_unknown_admin_url_renders_a_404_inside_the_admin_panel_for_admins(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/no-such-page')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Errors/Panel')
                ->where('area', 'Admin')
                ->where('home', '/admin'));
    }

    public function test_missing_record_inside_the_panel_keeps_the_panel_layout(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/staff/posts/999999/edit')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('Errors/Panel')->where('area', 'Staff'));
    }

    public function test_staff_opening_an_admin_page_get_an_unauthorised_page_in_the_staff_panel(): void
    {
        foreach (['/admin', '/admin/users', '/admin/no-such-page'] as $path) {
            $this->actingAs(User::factory()->staff()->create())
                ->get($path)
                ->assertForbidden()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Errors/Panel')
                    ->where('status', 403)
                    ->where('area', 'Staff')
                    ->where('home', '/staff')
                    ->where('requiredRole', 'Admin')
                    ->where('message', 'This page needs the Admin role. You are signed in as Staff.'));
        }
    }

    public function test_staff_forbidden_inside_the_staff_panel_are_told_their_role(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/staff/posts/review')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Errors/Panel')
                ->where('area', 'Staff')
                ->where('requiredRole', null));
    }

    public function test_public_users_and_guests_keep_the_public_error_pages(): void
    {
        $this->get('/staff/no-such-page')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())
            ->get('/staff')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Errors/Show')->where('status', 403));
    }

    public function test_public_404_is_unchanged(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/articles/does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Errors/Show')
                ->where('title', 'Page not found')
                ->where('message', 'We could not find that page in the APES Newsroom.'));
    }

    public function test_json_requests_still_get_json_errors(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->getJson('/admin/users')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }
}
