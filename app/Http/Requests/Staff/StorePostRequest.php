<?php

namespace App\Http\Requests\Staff;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    use PostPayloadRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->baseRules(),
            'slug' => ['nullable', 'string', 'max:255', 'unique:posts,slug'],
        ];
    }
}
