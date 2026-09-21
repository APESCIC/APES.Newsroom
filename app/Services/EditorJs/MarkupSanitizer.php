<?php

namespace App\Services\EditorJs;

use Illuminate\Validation\ValidationException;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Converts and sanitizes Markdown/HTML markup blocks for Editor.js (#74).
 */
class MarkupSanitizer
{
    public const ALLOWED_TAGS = '<p><br><strong><em><ul><ol><li><a><h2><h3><h4><blockquote><img><figure><figcaption><table><thead><tbody><tr><th><td><hr><code><pre>';

    private const MAX_LENGTH = 100000;

    /**
     * @return array{format: string, source: string, html: string}
     */
    public function sanitize(string $format, string $source, int $index): array
    {
        if (! in_array($format, ['markdown', 'html'], true)) {
            throw ValidationException::withMessages([
                'content' => "Markup format at index {$index} must be markdown or html.",
            ]);
        }

        if (strlen($source) > self::MAX_LENGTH) {
            throw ValidationException::withMessages([
                'content' => "Markup at index {$index} exceeds maximum length.",
            ]);
        }

        if ($this->containsDisallowedPatterns($source)) {
            throw ValidationException::withMessages([
                'content' => "Disallowed content in markup at index {$index}.",
            ]);
        }

        $html = $format === 'markdown'
            ? $this->markdownToHtml($source)
            : $source;

        $sanitized = $this->stripToAllowlist($html);

        if ($this->containsDisallowedPatterns($sanitized)) {
            throw ValidationException::withMessages([
                'content' => "Disallowed content in markup at index {$index} after sanitization.",
            ]);
        }

        return [
            'format' => $format,
            'source' => $source,
            'html' => $sanitized,
        ];
    }

    public function stripToAllowlist(string $html): string
    {
        return strip_tags($html, self::ALLOWED_TAGS);
    }

    private function markdownToHtml(string $source): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 32,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);

        $converter = new MarkdownConverter($environment);

        return (string) $converter->convert($source);
    }

    private function containsDisallowedPatterns(string $value): bool
    {
        return (bool) preg_match('/<script|javascript:|on\w+\s*=/i', $value);
    }
}
