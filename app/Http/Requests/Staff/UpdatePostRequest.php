<?php

namespace App\Http\Requests\Staff;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    use PostPayloadRules;

    public function authorize(): bool
    {
        $post = $this->route('post');

        return $post instanceof Post && ($this->user()?->can('update', $post) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->baseRules(),
            'slug' => ['required', 'string', 'max:255', Rule::unique('posts', 'slug')->ignore($this->route('post'))],
        ];
    }
}
