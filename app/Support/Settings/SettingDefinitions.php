<?php

namespace App\Support\Settings;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Single source of truth for admin-managed site settings.
 *
 * Every key the SettingsRepository reads or writes must be declared here with
 * its type, default and presentation metadata for the admin Settings page.
 */
final class SettingDefinitions
{
    public const EDITOR_DRIVER = 'editor.driver';

    public const EDITOR_DRIVERS = ['editorjs', 'tinymce'];

    /**
     * @return array<string, array{label: string, help: string, type: 'enum', default: string, options: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            self::EDITOR_DRIVER => [
                'label' => 'Staff editor',
                'help' => 'Rich-text editor used on staff post and page screens. Content is always stored as Editor.js blocks. TinyMCE support arrives in a later release; until then it falls back to Editor.js.',
                'type' => 'enum',
                'default' => 'editorjs',
                'options' => [
                    'editorjs' => 'Editor.js',
                    'tinymce' => 'TinyMCE',
                ],
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array{label: string, help: string, type: 'enum', default: string, options: array<string, string>}
     */
    public static function get(string $key): array
    {
        $definitions = self::all();

        if (! array_key_exists($key, $definitions)) {
            throw new InvalidArgumentException("Undeclared setting [{$key}].");
        }

        return $definitions[$key];
    }

    public static function accepts(string $key, mixed $value): bool
    {
        $definition = self::get($key);

        return match ($definition['type']) {
            'enum' => is_string($value) && array_key_exists($value, $definition['options']),
        };
    }

    /**
     * @return list<mixed>
     */
    public static function rules(string $key): array
    {
        $definition = self::get($key);

        return match ($definition['type']) {
            'enum' => ['required', 'string', Rule::in(array_keys($definition['options']))],
        };
    }
}
