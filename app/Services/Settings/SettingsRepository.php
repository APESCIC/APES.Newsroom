<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class SettingsRepository
{
    public const CACHE_KEY = 'settings:all';

    public function get(string $key): mixed
    {
        $definition = SettingDefinitions::get($key);
        $stored = $this->stored();

        if (array_key_exists($key, $stored) && SettingDefinitions::accepts($key, $stored[$key])) {
            return $stored[$key];
        }

        return $definition['default'];
    }

    public function set(string $key, mixed $value): void
    {
        if (! SettingDefinitions::accepts($key, $value)) {
            throw new InvalidArgumentException("Invalid value for setting [{$key}].");
        }

        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];

        foreach (array_keys(SettingDefinitions::all()) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()
            ->get(['key', 'value'])
            ->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value])
            ->all());
    }
}
