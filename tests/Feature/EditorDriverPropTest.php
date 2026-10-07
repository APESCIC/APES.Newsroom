<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EditorDriverPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_pages_do_not_share_editor_driver(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('editorDriver'));
    }

    public function test_public_member_pages_do_not_share_editor_driver(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('editorDriver'));
    }

    public function test_staff_receive_default_editor_driver(): void
    {
        $this->actingAs(User::factory()->staff()->create())
            ->get('/staff/posts/new')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('editorDriver', 'editorjs'));
    }

    public function test_editor_driver_follows_the_setting(): void
    {
        app(SettingsRepository::class)->set(SettingDefinitions::EDITOR_DRIVER, 'tinymce');

        $this->actingAs(User::factory()->staff()->create())
            ->get('/staff/posts/new')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('editorDriver', 'tinymce'));

        $this->actingAs(User::factory()->admin()->create())
            ->get('/staff/pages/new')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('editorDriver', 'tinymce'));
    }
}
