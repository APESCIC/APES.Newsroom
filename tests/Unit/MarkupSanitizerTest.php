<?php

namespace Tests\Unit;

use App\Services\EditorJs\MarkupSanitizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarkupSanitizerTest extends TestCase
{
    public function test_markdown_converts_to_sanitized_html(): void
    {
        $result = (new MarkupSanitizer)->sanitize(
            'markdown',
            "## Hello\n\nA **bold** [link](https://example.com).",
            0,
        );

        $this->assertSame('markdown', $result['format']);
        $this->assertStringContainsString('<h2>Hello</h2>', $result['html']);
        $this->assertStringContainsString('<strong>bold</strong>', $result['html']);
        $this->assertStringContainsString('href="https://example.com"', $result['html']);
        $this->assertStringNotContainsString('<script', $result['html']);
    }

    public function test_html_strips_disallowed_tags_but_keeps_safe_markup(): void
    {
        $result = (new MarkupSanitizer)->sanitize(
            'html',
            '<p>Safe <strong>text</strong></p><iframe src="https://evil.example"></iframe>',
            0,
        );

        $this->assertStringContainsString('<p>Safe <strong>text</strong></p>', $result['html']);
        $this->assertStringNotContainsString('iframe', $result['html']);
    }

    public function test_script_in_html_source_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        (new MarkupSanitizer)->sanitize(
            'html',
            '<p>Hi</p><script>alert(1)</script>',
            0,
        );
    }

    public function test_javascript_href_in_markdown_source_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        (new MarkupSanitizer)->sanitize(
            'markdown',
            '[click](javascript:alert(1))',
            0,
        );
    }
}
