<?php

namespace App\Http\Requests\Staff;

use App\Enums\ContentVisibility;
use App\Models\Page;
use App\Services\Media\HeroImageInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePageRequest extends FormRequest
{
    use EditorPayloadRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Page::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('pages', 'slug')],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            ...$this->editorPayloadRules(),
            'visibility' => ['sometimes', Rule::enum(ContentVisibility::class)],
            'hero_image' => ['nullable', 'string', 'max:2048'],
            'hero_image_alt' => ['nullable', 'string', 'max:255'],
            'hero_image_caption' => ['nullable', 'string', 'max:500'],
            'hero_image_credit' => ['nullable', 'string', 'max:255'],
            ...HeroImageInput::rules(),
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'expected_updated_at' => ['nullable', 'string'],
        ];
    }
}
