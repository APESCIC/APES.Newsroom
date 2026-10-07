<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_declared_settings_with_current_values_and_help(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Index')
                ->has('settings', 1)
                ->where('settings.0.key', SettingDefinitions::EDITOR_DRIVER)
                ->where('settings.0.type', 'enum')
                ->where('settings.0.value', 'editorjs')
                ->where('settings.0.options.0.value', 'editorjs')
                ->where('settings.0.options.1.value', 'tinymce')
                ->where('settings.0.help', fn (string $help) => $help !== ''));
    }

    public function test_edit_reflects_saved_value(): void
    {
        $admin = User::factory()->admin()->create();
        app(SettingsRepository::class)->set(SettingDefinitions::EDITOR_DRIVER, 'tinymce');

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertInertia(fn (Assert $page) => $page->where('settings.0.value', 'tinymce'));
    }

    public function test_invalid_value_returns_inline_error_and_is_not_persisted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from('/admin/settings')
            ->put('/admin/settings', ['settings' => ['editor' => ['driver' => 'word']]])
            ->assertRedirect('/admin/settings')
            ->assertSessionHasErrors('settings.editor.driver');

        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'settings.updated')->count());
    }

    public function test_missing_value_returns_inline_error(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from('/admin/settings')
            ->put('/admin/settings', ['settings' => []])
            ->assertSessionHasErrors('settings.editor.driver');
    }

    public function test_valid_value_persists_writes_audit_log_and_flashes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put('/admin/settings', ['settings' => ['editor' => ['driver' => 'tinymce']]])
            ->assertRedirect('/admin/settings')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Settings saved.');

        $this->assertSame('tinymce', app(SettingsRepository::class)->get(SettingDefinitions::EDITOR_DRIVER));

        $log = AuditLog::query()->where('action', 'settings.updated')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame(
            ['changes' => [SettingDefinitions::EDITOR_DRIVER => ['from' => 'editorjs', 'to' => 'tinymce']]],
            $log->payload,
        );
    }

    public function test_unchanged_value_does_not_write_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put('/admin/settings', ['settings' => ['editor' => ['driver' => 'editorjs']]])
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('status', 'Settings saved.');

        $this->assertSame(0, Setting::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'settings.updated')->count());
    }
}
