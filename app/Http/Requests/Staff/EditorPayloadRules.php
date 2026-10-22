<?php

namespace App\Http\Requests\Staff;

use App\Support\Settings\SettingDefinitions;
use Illuminate\Validation\Rule;

trait EditorPayloadRules
{
    /**
     * Editor.js sends `content` blocks; TinyMCE sends `content_html` with `editor=tinymce`.
     *
     * @return array<string, mixed>
     */
    protected function editorPayloadRules(): array
    {
        return [
            'editor' => ['sometimes', 'string', Rule::in(SettingDefinitions::EDITOR_DRIVERS)],
            'content' => ['exclude_if:editor,tinymce', 'required', 'array'],
            'content_html' => ['exclude_unless:editor,tinymce', 'present', 'nullable', 'string', 'max:1000000'],
        ];
    }
}
