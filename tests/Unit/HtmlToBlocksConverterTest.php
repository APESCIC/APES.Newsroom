<?php

namespace Tests\Unit;

use App\Services\EditorJs\BlockValidator;
use App\Services\EditorJs\EditorHtmlPresenter;
use App\Services\EditorJs\HtmlToBlocksConverter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HtmlToBlocksConverterTest extends TestCase
{
    public function test_tinymce_html_maps_to_editorjs_blocks(): void
    {
        $blocks = (new HtmlToBlocksConverter)->convert(
            '<h1>Big</h1>'
            .'<p>Hello <b>world</b> &amp; <a href="https://example.com">friends</a></p>'
            .'<h3>Sub</h3><h6>Tiny</h6>'
            .'<ul><li>One<ul><li>One.a</li></ul></li><li><em>Two</em></li></ul>'
            .'<ol><li>First</li></ol>'
            .'<blockquote><p>Said this</p><p><cite>Someone</cite></p></blockquote>'
            .'<figure class="image" data-credit="Photo: APES"><img src="https://cdn.example.com/a.jpg" alt="A dog"><figcaption>Rex</figcaption></figure>'
            .'<p><img src="https://cdn.example.com/b.jpg" alt="B"></p>'
            .'<table><thead><tr><th>H1</th><th>H2</th></tr></thead><tbody><tr><td><strong>a</strong></td><td>b</td></tr></tbody></table>'
            .'<hr>'
            .'<pre>raw &lt;code&gt;</pre>'
        );

        $this->assertSame([
            ['type' => 'header', 'data' => ['text' => 'Big', 'level' => 2]],
            ['type' => 'paragraph', 'data' => ['text' => 'Hello <strong>world</strong> &amp; <a href="https://example.com" rel="noopener noreferrer">friends</a>']],
            ['type' => 'header', 'data' => ['text' => 'Sub', 'level' => 3]],
            ['type' => 'header', 'data' => ['text' => 'Tiny', 'level' => 4]],
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['One', 'One.a', '<em>Two</em>']]],
            ['type' => 'list', 'data' => ['style' => 'ordered', 'items' => ['First']]],
            ['type' => 'quote', 'data' => ['text' => 'Said this', 'caption' => 'Someone']],
            ['type' => 'image', 'data' => ['file' => ['url' => 'https://cdn.example.com/a.jpg'], 'alt' => 'A dog', 'caption' => 'Rex', 'credit' => 'Photo: APES']],
            ['type' => 'image', 'data' => ['file' => ['url' => 'https://cdn.example.com/b.jpg'], 'alt' => 'B', 'caption' => '', 'credit' => '']],
            ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['H1', 'H2'], ['<strong>a</strong>', 'b']]]],
            ['type' => 'delimiter', 'data' => []],
            ['type' => 'paragraph', 'data' => ['text' => 'raw &lt;code&gt;']],
        ], $blocks);
    }

    public function test_empty_html_becomes_one_empty_paragraph(): void
    {
        $this->assertSame(
            [['type' => 'paragraph', 'data' => ['text' => '']]],
            (new HtmlToBlocksConverter)->convert(''),
        );
    }

    public function test_loose_inline_content_is_wrapped_in_paragraphs(): void
    {
        $this->assertSame([
            ['type' => 'paragraph', 'data' => ['text' => 'Loose <strong>text</strong>']],
            ['type' => 'paragraph', 'data' => ['text' => 'Para']],
            ['type' => 'paragraph', 'data' => ['text' => 'Tail']],
        ], (new HtmlToBlocksConverter)->convert('Loose <strong>text</strong><p>Para</p><div>Tail</div>'));
    }

    public function test_disallowed_markup_is_removed(): void
    {
        $blocks = (new HtmlToBlocksConverter)->convert(
            '<p style="color:red" onclick="x()">Safe <span style="font-size:40px">text</span><img src="https://cdn.example.com/a.jpg" alt="A" onerror="alert(1)"></p>'
            .'<script>alert(1)</script><iframe src="https://evil.example"></iframe><style>p{}</style>'
        );

        $document = (new BlockValidator)->validate(['blocks' => $blocks]);
        $json = json_encode($document);

        $this->assertSame('Safe text', $document['blocks'][0]['data']['text']);
        $this->assertSame('image', $document['blocks'][1]['type']);
        $this->assertCount(2, $document['blocks']);
        $this->assertStringNotContainsString('onclick', $json);
        $this->assertStringNotContainsString('style', $json);
        $this->assertStringNotContainsString('alert', $json);
        $this->assertStringNotContainsString('evil.example', $json);
    }

    public function test_placeholders_restore_their_original_block(): void
    {
        $callout = ['type' => 'callout', 'data' => ['text' => 'Mind the "gap" & <stuff>']];
        $html = (new EditorHtmlPresenter)->toHtml(['blocks' => [$callout]]);

        $this->assertStringContainsString('contenteditable="false"', $html);
        $this->assertStringContainsString('aria-label="Callout block. Read-only here; edit it in Editor.js."', $html);
        $this->assertSame([$callout], (new HtmlToBlocksConverter)->convert('<p>'.$html.'</p>'));
    }

    public function test_tampered_placeholders_are_rejected(): void
    {
        foreach ([
            '<div data-block-type="callout" data-block="{not json">x</div>',
            '<div data-block-type="paragraph" data-block="{&quot;text&quot;:&quot;x&quot;}">x</div>',
            '<div data-block-type="script" data-block="{}">x</div>',
        ] as $html) {
            try {
                (new HtmlToBlocksConverter)->convert($html);
                $this->fail('Expected a validation error for '.$html);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('content', $exception->errors());
            }
        }
    }

    public function test_forged_placeholder_data_still_passes_the_validator(): void
    {
        $blocks = (new HtmlToBlocksConverter)->convert(
            '<div data-block-type="bookmark" data-block="{&quot;url&quot;:&quot;javascript:alert(1)&quot;}">x</div>'
        );

        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate(['blocks' => $blocks]);
    }
}
