<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class SettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }

    public function test_unsaved_setting_returns_declared_default(): void
    {
        $this->assertSame('editorjs', $this->repository()->get(SettingDefinitions::EDITOR_DRIVER));
        $this->assertSame([SettingDefinitions::EDITOR_DRIVER => 'editorjs'], $this->repository()->all());
    }

    public function test_undeclared_key_is_rejected_on_read(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository()->get('not.declared');
    }

    public function test_undeclared_key_is_rejected_on_write(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository()->set('not.declared', 'value');
    }

    public function test_invalid_value_is_rejected_on_write(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository()->set(SettingDefinitions::EDITOR_DRIVER, 'word');
    }

    public function test_set_persists_value(): void
    {
        $this->repository()->set(SettingDefinitions::EDITOR_DRIVER, 'tinymce');

        $this->assertSame(1, Setting::query()->count());
        $this->assertSame('tinymce', Setting::query()->first()?->value);

        $this->repository()->set(SettingDefinitions::EDITOR_DRIVER, 'editorjs');

        $this->assertSame(1, Setting::query()->count());
        $this->assertSame('editorjs', Setting::query()->first()?->value);
    }

    public function test_reads_are_cached_and_writes_invalidate_cache(): void
    {
        $this->assertSame('editorjs', $this->repository()->get(SettingDefinitions::EDITOR_DRIVER));
        $this->assertTrue(Cache::has(SettingsRepository::CACHE_KEY));

        Setting::query()->create(['key' => SettingDefinitions::EDITOR_DRIVER, 'value' => 'tinymce']);
        $this->assertSame('editorjs', $this->repository()->get(SettingDefinitions::EDITOR_DRIVER));

        $this->repository()->set(SettingDefinitions::EDITOR_DRIVER, 'tinymce');

        $this->assertSame('tinymce', $this->repository()->get(SettingDefinitions::EDITOR_DRIVER));
    }

    public function test_stored_value_outside_declared_options_falls_back_to_default(): void
    {
        Setting::query()->create(['key' => SettingDefinitions::EDITOR_DRIVER, 'value' => 'retired-editor']);

        $this->assertSame('editorjs', $this->repository()->get(SettingDefinitions::EDITOR_DRIVER));
    }
}
