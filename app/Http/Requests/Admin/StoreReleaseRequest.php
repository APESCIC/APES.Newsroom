<?php

namespace App\Http\Requests\Admin;

use App\Enums\ReleaseChannel;
use App\Enums\Role;
use App\Models\Release;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->atLeast(Role::Admin) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $releaseId = $this->route('release')?->id;

        return [
            'version' => ['required', 'string', 'max:64'],
            'previous_version' => ['nullable', 'string', 'max:64'],
            'released_at' => ['required', 'date'],
            'channel' => ['required', Rule::enum(ReleaseChannel::class)],
            'version_type' => ['nullable', 'string', 'max:120'],
            'theme' => ['nullable', 'string', 'max:160'],
            'is_current' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('releases', 'slug')->ignore($releaseId),
            ],
            'change_types' => ['nullable', 'array'],
            'change_types.*' => ['string', Rule::in(Release::CHANGE_TYPES)],
            'topic_tags' => ['nullable', 'array'],
            'topic_tags.*' => ['string', Rule::in(Release::TOPIC_TAGS)],
            'summary' => ['required', 'string'],
            'detailed_changes' => ['nullable', 'array'],
            'detailed_changes.*' => ['string'],
            'affected_areas' => ['nullable', 'array'],
            'affected_areas.*' => ['string'],
            'version_decision' => ['nullable', 'array'],
            'version_decision.*' => ['string'],
            'validation' => ['nullable', 'array'],
            'validation.*' => ['string'],
            // Textarea wire format (one item per line) — converted in prepareForValidation
            'detailed_changes_text' => ['nullable', 'string'],
            'affected_areas_text' => ['nullable', 'string'],
            'version_decision_text' => ['nullable', 'string'],
            'validation_text' => ['nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_current' => $this->boolean('is_current'),
            'is_published' => $this->boolean('is_published'),
            'change_types' => array_values(array_filter((array) $this->input('change_types', []))),
            'topic_tags' => array_values(array_filter((array) $this->input('topic_tags', []))),
            'detailed_changes' => $this->linesToList($this->input('detailed_changes_text')),
            'affected_areas' => $this->linesToList($this->input('affected_areas_text')),
            'version_decision' => $this->linesToList($this->input('version_decision_text')),
            'validation' => $this->linesToList($this->input('validation_text')),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('is_published')) {
                return;
            }

            foreach (['detailed_changes', 'affected_areas', 'version_decision', 'validation'] as $field) {
                $items = $this->input($field, []);
                if (! is_array($items) || count(array_filter($items, fn ($item) => is_string($item) && trim($item) !== '')) === 0) {
                    $validator->errors()->add($field, 'Published releases require at least one '.$field.' item.');
                }
            }

            if (trim((string) $this->input('summary', '')) === '') {
                $validator->errors()->add('summary', 'Published releases require a summary.');
            }
        });
    }

    /**
     * @return list<string>
     */
    private function linesToList(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            $lines,
        ), static fn (string $line): bool => $line !== ''));
    }
}
