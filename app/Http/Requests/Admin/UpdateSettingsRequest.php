<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->atLeast(Role::Admin) ?? false;
    }

    /**
     * Setting keys contain dots, so the form posts them nested under
     * `settings` (e.g. settings[editor][driver]) and rules use the same path.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach (array_keys(SettingDefinitions::all()) as $key) {
            $rules['settings.'.$key] = SettingDefinitions::rules($key);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (SettingDefinitions::all() as $key => $definition) {
            $attributes['settings.'.$key] = strtolower($definition['label']);
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    public function settingValues(): array
    {
        $values = [];

        foreach (array_keys(SettingDefinitions::all()) as $key) {
            $values[$key] = $this->validated('settings.'.$key);
        }

        return $values;
    }
}
