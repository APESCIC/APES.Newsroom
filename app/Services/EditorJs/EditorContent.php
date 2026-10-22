<?php

namespace App\Services\EditorJs;

use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;

/**
 * Staff editor save/load path for both editor drivers (issues #257, #261).
 *
 * The request's own `editor` value decides how content is read, so a save
 * still converts correctly if an admin switched the site setting mid-session.
 */
class EditorContent
{
    public function __construct(
        private readonly BlockValidator $validator,
        private readonly HtmlToBlocksConverter $converter,
        private readonly EditorHtmlPresenter $presenter,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function fromPayload(array $validated): array
    {
        if (($validated['editor'] ?? 'editorjs') === 'tinymce') {
            return $this->validator->validate([
                'blocks' => $this->converter->convert((string) ($validated['content_html'] ?? '')),
            ]);
        }

        return $this->validator->validate($validated['content']);
    }

    /**
     * Editor HTML for the edit screen, only when the site uses TinyMCE.
     *
     * @param  array<string, mixed>|null  $document
     */
    public function editorHtml(?array $document): ?string
    {
        if ($this->settings->get(SettingDefinitions::EDITOR_DRIVER) !== 'tinymce') {
            return null;
        }

        return $this->presenter->toHtml($document ?? []);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function withoutEditorFields(array $validated): array
    {
        unset($validated['editor'], $validated['content'], $validated['content_html']);

        return $validated;
    }
}
