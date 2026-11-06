<?php

namespace App\Services\EmailTemplates;

use App\Support\EmailTemplates\TemplateDefinition;
use DomainException;
use Illuminate\Support\Str;

/**
 * Fills {{ placeholders }} safely. Never evaluates Blade or PHP from stored
 * template content — replacements are escaped strings only.
 */
class TemplateRenderer
{
    private const TAG_PATTERN = '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/u';

    public function __construct(private readonly TemplateHtmlSanitizer $sanitizer = new TemplateHtmlSanitizer) {}

    /**
     * @param  array<string, string|int|float|null>  $placeholders
     * @param  list<string>  $allowed
     */
    public function render(string $template, array $placeholders, array $allowed): string
    {
        foreach ($this->extractTags($template) as $tag) {
            if (! in_array($tag, $allowed, true)) {
                throw new DomainException("Placeholder [{$tag}] is not allowed for this template.");
            }
        }

        return (string) preg_replace_callback(self::TAG_PATTERN, function (array $matches) use ($placeholders): string {
            $key = $matches[1];
            $value = $placeholders[$key] ?? '';

            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }, $template);
    }

    /**
     * Plain subject: placeholders escaped then decoded so entities are not
     * shown literally in the Subject header.
     *
     * @param  array<string, string|int|float|null>  $placeholders
     * @param  list<string>  $allowed
     */
    public function renderSubject(string $template, array $placeholders, array $allowed): string
    {
        $rendered = $this->render($template, $placeholders, $allowed);

        return html_entity_decode(strip_tags($rendered), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    public function validate(string $subject, string $body, TemplateDefinition $def): array
    {
        $errors = [];
        $allowed = $def->allowedPlaceholders;
        $present = array_values(array_unique([
            ...$this->extractTags($subject),
            ...$this->extractTags($body),
        ]));

        foreach ($present as $tag) {
            if (! in_array($tag, $allowed, true)) {
                $errors[] = "Unknown placeholder {{{$tag}}}.";
            }
        }

        foreach ($def->requiredPlaceholders as $required) {
            if (! in_array($required, $present, true)) {
                $errors[] = "Required placeholder {{{$required}}} is missing.";
            }
        }

        return $errors;
    }

    public function toHtml(string $markdownWithPlaceholdersFilled): string
    {
        $html = Str::markdown($markdownWithPlaceholdersFilled, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $this->sanitizer->sanitize($html);
    }

    public function toText(string $markdownOrHtml): string
    {
        $text = $markdownOrHtml;

        if (str_contains($text, '<')) {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = preg_replace('/^#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $text) ?? $text;
        $text = preg_replace('/(\*|_)(.*?)\1/', '$2', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $text) ?? $text;

        return trim(preg_replace("/[ \t]+\n/", "\n", $text) ?? $text);
    }

    /**
     * @return list<string>
     */
    public function extractTags(string $template): array
    {
        preg_match_all(self::TAG_PATTERN, $template, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
