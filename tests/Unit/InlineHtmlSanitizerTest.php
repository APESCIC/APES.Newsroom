<?php

namespace Tests\Unit;

use App\Services\EditorJs\InlineHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InlineHtmlSanitizerTest extends TestCase
{
    public function test_allowed_inline_formatting_is_kept(): void
    {
        $html = (new InlineHtmlSanitizer)->sanitize(
            'A <strong>bold</strong>, <em>italic</em>, <code>code</code> and <a href="https://example.com/x?a=1&amp;b=2">link</a>.<br>Next'
        );

        $this->assertSame(
            'A <strong>bold</strong>, <em>italic</em>, <code>code</code> and <a href="https://example.com/x?a=1&amp;b=2" rel="noopener noreferrer">link</a>.<br>Next',
            $html,
        );
    }

    public function test_b_and_i_are_normalised(): void
    {
        $this->assertSame(
            '<strong>B</strong> <em>I</em>',
            (new InlineHtmlSanitizer)->sanitize('<b>B</b> <i>I</i>'),
        );
    }

    public function test_plain_text_is_escaped_and_stable(): void
    {
        $sanitizer = new InlineHtmlSanitizer;

        $this->assertSame('', $sanitizer->sanitize(''));
        $this->assertSame('Plain text', $sanitizer->sanitize('Plain text'));
        $this->assertSame('Tom &amp; Jerry', $sanitizer->sanitize('Tom & Jerry'));
        $this->assertSame('Tom &amp; Jerry', $sanitizer->sanitize('Tom &amp; Jerry'));
        $this->assertSame('a &lt; b &gt; c', $sanitizer->sanitize('a < b > c'));
        $this->assertSame('it’s', $sanitizer->sanitize('it&rsquo;s'));
        $this->assertSame(
            $sanitizer->sanitize('Tom & Jerry <em>x</em>'),
            $sanitizer->sanitize($sanitizer->sanitize('Tom & Jerry <em>x</em>')),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hostileInput(): array
    {
        return [
            'script' => ['x<script>alert(1)</script>y', 'xy'],
            'style element' => ['x<style>p{color:red}</style>y', 'xy'],
            'event handler' => ['<strong onclick="alert(1)">x</strong>', '<strong>x</strong>'],
            'img onerror' => ['x<img src=x onerror=alert(1)>y', 'xy'],
            'javascript link' => ['<a href="javascript:alert(1)">x</a>', 'x'],
            'encoded javascript link' => ['<a href="jav&#x09;ascript:alert(1)">x</a>', 'x'],
            'data link' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>', 'x'],
            'relative link' => ['<a href="/admin">x</a>', 'x'],
            'style attribute' => ['<em style="color:red">x</em>', '<em>x</em>'],
            'span and font' => ['<span class="c"><font color="red">x</font></span>', 'x'],
            'svg' => ['<svg><a href="https://example.com">x</a><script>alert(1)</script></svg>y', 'y'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>y', 'y'],
            'nested link' => [
                '<a href="https://a.example">a<a href="https://b.example">b</a></a>',
                '<a href="https://a.example" rel="noopener noreferrer">a</a><a href="https://b.example" rel="noopener noreferrer">b</a>',
            ],
            'comment' => ['x<!-- hidden -->y', 'xy'],
            'attribute breakout' => [
                '<a href="https://example.com/&quot;onmouseover=&quot;alert(1)">x</a>',
                '<a href="https://example.com/&quot;onmouseover=&quot;alert(1)" rel="noopener noreferrer">x</a>',
            ],
        ];
    }

    #[DataProvider('hostileInput')]
    public function test_hostile_input_is_neutralised(string $input, string $expected): void
    {
        $this->assertSame($expected, (new InlineHtmlSanitizer)->sanitize($input));
    }

    public function test_mailto_links_are_allowed(): void
    {
        $this->assertSame(
            '<a href="mailto:news@example.com" rel="noopener noreferrer">Email</a>',
            (new InlineHtmlSanitizer)->sanitize('<a href="mailto:news@example.com" target="_blank">Email</a>'),
        );
    }
}
