<?php

namespace Tests\Feature\Seo;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSeoSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_view_and_update_seo_settings_with_audit(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->withRecentAuthentication()
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Settings/Index')
                ->has('settings')
                ->where('settings', fn ($settings) => collect($settings)->contains(
                    fn ($setting) => $setting['key'] === SettingDefinitions::SEO_TWITTER_HANDLE
                        && $setting['type'] === 'string'
                        && $setting['section'] === 'SEO'
                )));

        $this->actingAs($admin)->withRecentAuthentication()
            ->put('/admin/settings', [
                'settings' => [
                    'seo' => [
                        'twitter_handle' => '@apesnews',
                        'default_description' => 'Default description for the newsroom.',
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('@apesnews', app(SettingsRepository::class)->get(SettingDefinitions::SEO_TWITTER_HANDLE));
        $this->assertTrue(
            AuditLog::query()->where('action', 'settings.updated')->exists()
        );
    }
}
