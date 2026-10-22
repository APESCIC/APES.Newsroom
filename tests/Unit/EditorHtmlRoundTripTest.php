<?php

namespace Tests\Unit;

use App\Services\EditorJs\BlockValidator;
use App\Services\EditorJs\EditorHtmlPresenter;
use App\Services\EditorJs\HtmlToBlocksConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Blocks -> TinyMCE editor HTML -> blocks must be lossless (#259).
 *
 * Documented normalisation, applied by BlockValidator before the round trip:
 * b/i become strong/em, links gain rel="noopener noreferrer", and leading or
 * trailing whitespace in text fields is trimmed.
 */
class EditorHtmlRoundTripTest extends TestCase
{
    /**
     * @return array<string, array{0: array{type: string, data: array<string, mixed>}}>
     */
    public static function blocks(): array
    {
        return [
            'paragraph' => [['type' => 'paragraph', 'data' => ['text' => 'Plain words, nothing else']]],
            'paragraph with inline formatting' => [['type' => 'paragraph', 'data' => [
                'text' => 'A <b>bold</b>, <i>italic</i>, <code>x = 1</code> and <a href="https://apes.org.uk/a?b=1&amp;c=2">link</a><br>next line',
            ]]],
            'paragraph with entities' => [['type' => 'paragraph', 'data' => ['text' => 'Tom &amp; Jerry &lt;3 “quotes” café']]],
            'empty paragraph' => [['type' => 'paragraph', 'data' => ['text' => '']]],
            'header level 2' => [['type' => 'header', 'data' => ['text' => 'Second', 'level' => 2]]],
            'header level 3 with formatting' => [['type' => 'header', 'data' => ['text' => 'Third <em>level</em>', 'level' => 3]]],
            'header level 4' => [['type' => 'header', 'data' => ['text' => 'Fourth', 'level' => 4]]],
            'unordered list' => [['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['One', '<strong>Two</strong>', 'Three &amp; four']]]],
            'ordered list' => [['type' => 'list', 'data' => ['style' => 'ordered', 'items' => ['First', 'Second']]]],
            'quote with caption' => [['type' => 'quote', 'data' => ['text' => 'Line one<br>Line <em>two</em>', 'caption' => 'Someone & co']]],
            'quote without caption' => [['type' => 'quote', 'data' => ['text' => 'Unattributed', 'caption' => '']]],
            'image with caption and credit' => [['type' => 'image', 'data' => [
                'file' => ['url' => 'https://cdn.example.com/a.jpg?w=800&h=600'],
                'alt' => 'A "rescued" tortoise',
                'caption' => 'Rex & friends',
                'credit' => 'Photo: APES',
            ]]],
            'image without caption' => [['type' => 'image', 'data' => [
                'file' => ['url' => 'https://cdn.example.com/b.png'],
                'alt' => 'Plain',
                'caption' => '',
                'credit' => '',
            ]]],
            'table with headings' => [['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['Name', 'Age'], ['<strong>Rex</strong>', '102']]]]],
            'table without headings' => [['type' => 'table', 'data' => ['withHeadings' => false, 'content' => [['a', 'b'], ['c', '']]]]],
            'delimiter' => [['type' => 'delimiter', 'data' => []]],
            'callout' => [['type' => 'callout', 'data' => ['text' => 'Mind the "gap" & <stuff>']]],
            'toggle' => [['type' => 'toggle', 'data' => ['title' => 'More', 'content' => "Line 1\nLine 2"]]],
            'gallery' => [['type' => 'gallery', 'data' => [
                'items' => [
                    ['url' => 'https://cdn.example.com/1.jpg', 'alt' => 'One', 'caption' => 'First'],
                    ['url' => 'https://cdn.example.com/2.jpg', 'alt' => 'Two'],
                ],
                'caption' => 'Album',
            ]]],
            'video' => [['type' => 'video', 'data' => ['url' => 'https://cdn.example.com/clip.mp4', 'caption' => 'Clip', 'poster' => 'https://cdn.example.com/p.jpg']]],
            'audio' => [['type' => 'audio', 'data' => ['url' => 'https://cdn.example.com/track.mp3', 'caption' => 'Track']]],
            'file' => [['type' => 'file', 'data' => ['url' => 'https://cdn.example.com/guide.pdf', 'title' => 'Guide', 'size' => '1.2 MB']]],
            'bookmark' => [['type' => 'bookmark', 'data' => [
                'url' => 'https://example.com/article',
                'title' => 'Article',
                'description' => 'Summary',
                'image' => 'https://cdn.example.com/og.jpg',
            ]]],
            'product' => [['type' => 'product', 'data' => ['title' => 'Tote', 'description' => 'Cotton', 'url' => 'https://shop.example.com/tote', 'priceLabel' => '£12.50']]],
            'embed' => [['type' => 'embed', 'data' => [
                'service' => 'youtube',
                'source' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                'caption' => 'Video',
                'width' => 580,
                'height' => 320,
            ]]],
            'link preview' => [['type' => 'linkTool', 'data' => ['link' => 'https://example.com/page', 'meta' => ['title' => 'Example']]]],
            'markdown snippet' => [['type' => 'markup', 'data' => ['format' => 'markdown', 'source' => "## Hi\n\nSome **bold** text"]]],
            'html snippet' => [['type' => 'markup', 'data' => ['format' => 'html', 'source' => '<p>Safe <em>html</em></p>']]],
            'legacy' => [['type' => 'legacy', 'data' => ['html' => '<p>Imported <strong>table</strong></p><table><tr><td>x</td></tr></table>', 'note' => 'Imported legacy HTML']]],
        ];
    }

    /**
     * @param  array{type: string, data: array<string, mixed>}  $block
     */
    #[DataProvider('blocks')]
    public function test_each_block_type_round_trips_losslessly(array $block): void
    {
        $stored = $this->validator()->validate(['blocks' => [$block]]);

        $this->assertSame(
            $this->encode($stored['blocks']),
            $this->encode($this->roundTrip($stored, fn (string $html) => $html)),
        );
    }

    public function test_a_document_with_every_block_round_trips_through_tinymce_serialisation(): void
    {
        $stored = $this->validator()->validate([
            'blocks' => array_map(fn (array $case) => $case[0], array_values(self::blocks())),
        ]);

        // TinyMCE's getContent() drops contenteditable on placeholders and puts
        // a newline between block elements.
        $tinymce = fn (string $html) => str_replace(
            [' contenteditable="false"', '</p><', '</div><', '</ul><', '</ol><', '</figure><', '</table><', '</blockquote><', '<hr><'],
            ['', "</p>\n<", "</div>\n<", "</ul>\n<", "</ol>\n<", "</figure>\n<", "</table>\n<", "</blockquote>\n<", "<hr>\n<"],
            $html,
        );

        $this->assertSame($this->encode($stored['blocks']), $this->encode($this->roundTrip($stored, $tinymce)));
    }

    public function test_custom_block_data_is_byte_for_byte_identical_after_a_round_trip(): void
    {
        foreach (EditorHtmlPresenter::PLACEHOLDER_TYPES as $type) {
            $block = collect(self::blocks())->first(fn (array $case) => $case[0]['type'] === $type);
            $this->assertNotNull($block, "Missing round-trip fixture for {$type}");

            $stored = $this->validator()->validate(['blocks' => [$block[0]]]);
            $restored = (new HtmlToBlocksConverter)->convert((new EditorHtmlPresenter)->toHtml($stored));

            $this->assertSame($this->encode($stored['blocks'][0]['data']), $this->encode($restored[0]['data']), $type);
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  callable(string): string  $editor
     * @return list<array<string, mixed>>
     */
    private function roundTrip(array $stored, callable $editor): array
    {
        $html = $editor((new EditorHtmlPresenter)->toHtml($stored));

        return $this->validator()->validate(['blocks' => (new HtmlToBlocksConverter)->convert($html)])['blocks'];
    }

    private function validator(): BlockValidator
    {
        return new BlockValidator;
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
