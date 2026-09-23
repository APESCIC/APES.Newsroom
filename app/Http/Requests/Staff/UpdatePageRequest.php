<?php

namespace App\Http\Requests\Staff;

use App\Enums\ContentVisibility;
use App\Enums\Role;
use App\Models\Page;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role->atLeast(Role::Staff) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Page $page */
        $page = $this->route('page');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('pages', 'slug')->ignore($page->id)],
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'content' => ['required', 'array'],
            'visibility' => ['sometimes', Rule::enum(ContentVisibility::class)],
            'hero_image' => ['nullable', 'string', 'max:2048'],
            'hero_image_alt' => ['nullable', 'string', 'max:255'],
            'hero_image_caption' => ['nullable', 'string', 'max:500'],
            'hero_image_credit' => ['nullable', 'string', 'max:255'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'expected_updated_at' => ['nullable', 'string'],
        ];
    }
}
