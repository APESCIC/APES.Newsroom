<?php

namespace App\Http\Requests\Admin;

use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-admin-area') ?? false;
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
            foreach (SettingDefinitions::rules($key) as $suffix => $keyRules) {
                $rules['settings.'.$key.$suffix] = $keyRules;
            }
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
     * Only the settings present in the request; omitted ones keep their value.
     *
     * @return array<string, mixed>
     */
    public function settingValues(): array
    {
        $validated = $this->validated();
        $values = [];

        foreach (array_keys(SettingDefinitions::all()) as $key) {
            if (Arr::has($validated, 'settings.'.$key)) {
                $values[$key] = SettingDefinitions::cast($key, Arr::get($validated, 'settings.'.$key));
            }
        }

        return $values;
    }
}
