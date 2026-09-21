<?php

namespace Tests\Unit;

use App\Services\EditorJs\BlockValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlockValidatorTest extends TestCase
{
    public function test_valid_paragraph_block_passes(): void
    {
        $result = (new BlockValidator)->validate([
            'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Hello world']]],
        ]);

        $this->assertCount(1, $result['blocks']);
    }

    public function test_script_in_text_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [['type' => 'paragraph', 'data' => ['text' => '<script>alert(1)</script>']]],
        ]);
    }

    public function test_disallowed_block_type_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [['type' => 'raw', 'data' => ['html' => '<p>x</p>']]],
        ]);
    }

    public function test_image_requires_alt_text(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'image',
                'data' => ['file' => ['url' => 'https://example.com/img.jpg']],
            ]],
        ]);
    }

    public function test_table_and_embed_are_validated(): void
    {
        $result = (new BlockValidator)->validate([
            'blocks' => [
                [
                    'type' => 'table',
                    'data' => [
                        'withHeadings' => true,
                        'content' => [['A', 'B'], ['1', '2']],
                    ],
                ],
                [
                    'type' => 'embed',
                    'data' => [
                        'service' => 'youtube',
                        'source' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                        'embed' => 'https://www.youtube.com/embed/dQw4w9WgXcQ',
                    ],
                ],
            ],
        ]);

        $this->assertSame('table', $result['blocks'][0]['type']);
        $this->assertSame('embed', $result['blocks'][1]['type']);
    }

    public function test_unapproved_embed_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'embed',
                'data' => [
                    'service' => 'unknown',
                    'source' => 'https://example.com/x',
                ],
            ]],
        ]);
    }

    public function test_gallery_video_and_audio_are_validated(): void
    {
        $result = (new BlockValidator)->validate([
            'blocks' => [
                [
                    'type' => 'gallery',
                    'data' => [
                        'items' => [
                            [
                                'url' => 'https://cdn.example.com/a.jpg',
                                'alt' => 'A photo',
                                'caption' => 'First',
                            ],
                        ],
                        'caption' => 'Album',
                    ],
                ],
                [
                    'type' => 'video',
                    'data' => [
                        'url' => 'https://cdn.example.com/clip.mp4',
                        'poster' => 'https://cdn.example.com/poster.jpg',
                        'caption' => 'Clip',
                    ],
                ],
                [
                    'type' => 'audio',
                    'data' => [
                        'url' => 'https://cdn.example.com/track.mp3',
                    ],
                ],
            ],
        ]);

        $this->assertSame('gallery', $result['blocks'][0]['type']);
        $this->assertSame('video', $result['blocks'][1]['type']);
        $this->assertSame('audio', $result['blocks'][2]['type']);
        $this->assertSame('A photo', $result['blocks'][0]['data']['items'][0]['alt']);
        $this->assertArrayNotHasKey('caption', $result['blocks'][2]['data']);
    }

    public function test_gallery_requires_alt_text(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'gallery',
                'data' => [
                    'items' => [
                        ['url' => 'https://cdn.example.com/a.jpg', 'alt' => ''],
                    ],
                ],
            ]],
        ]);
    }

    public function test_video_rejects_non_http_url(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'video',
                'data' => ['url' => 'javascript:alert(1)'],
            ]],
        ]);
    }

    public function test_audio_requires_url(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'audio',
                'data' => ['url' => ''],
            ]],
        ]);
    }

    public function test_file_bookmark_product_and_toggle_are_validated(): void
    {
        $result = (new BlockValidator)->validate([
            'blocks' => [
                [
                    'type' => 'file',
                    'data' => [
                        'url' => 'https://cdn.example.com/guide.pdf',
                        'title' => 'Guide',
                        'size' => '1.2 MB',
                    ],
                ],
                [
                    'type' => 'bookmark',
                    'data' => [
                        'url' => 'https://example.com/article',
                        'title' => 'Article',
                        'description' => 'A summary',
                        'image' => 'https://cdn.example.com/og.jpg',
                    ],
                ],
                [
                    'type' => 'product',
                    'data' => [
                        'title' => 'Tote bag',
                        'description' => 'Cotton',
                        'url' => 'https://shop.example.com/tote',
                        'priceLabel' => '£12',
                    ],
                ],
                [
                    'type' => 'toggle',
                    'data' => [
                        'title' => 'More info',
                        'content' => 'Details here',
                    ],
                ],
            ],
        ]);

        $this->assertSame('file', $result['blocks'][0]['type']);
        $this->assertSame('bookmark', $result['blocks'][1]['type']);
        $this->assertSame('product', $result['blocks'][2]['type']);
        $this->assertSame('toggle', $result['blocks'][3]['type']);
        $this->assertSame('Guide', $result['blocks'][0]['data']['title']);
        $this->assertSame('£12', $result['blocks'][2]['data']['priceLabel']);
    }

    public function test_file_requires_http_url(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'file',
                'data' => ['url' => 'javascript:alert(1)'],
            ]],
        ]);
    }

    public function test_product_requires_title(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'product',
                'data' => ['title' => ''],
            ]],
        ]);
    }

    public function test_bookmark_rejects_non_http_image(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'bookmark',
                'data' => [
                    'url' => 'https://example.com/a',
                    'image' => 'javascript:alert(1)',
                ],
            ]],
        ]);
    }

    public function test_markup_markdown_and_html_are_validated(): void
    {
        $result = (new BlockValidator)->validate([
            'blocks' => [
                [
                    'type' => 'markup',
                    'data' => [
                        'format' => 'markdown',
                        'source' => "## Title\n\nParagraph",
                    ],
                ],
                [
                    'type' => 'markup',
                    'data' => [
                        'format' => 'html',
                        'source' => '<p>Hello <em>world</em></p>',
                    ],
                ],
            ],
        ]);

        $this->assertSame('markup', $result['blocks'][0]['type']);
        $this->assertSame('markdown', $result['blocks'][0]['data']['format']);
        $this->assertStringContainsString('<h2>Title</h2>', $result['blocks'][0]['data']['html']);
        $this->assertSame('<p>Hello <em>world</em></p>', $result['blocks'][1]['data']['html']);
    }

    public function test_markup_rejects_script(): void
    {
        $this->expectException(ValidationException::class);

        (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'markup',
                'data' => [
                    'format' => 'html',
                    'source' => '<p onclick="alert(1)">x</p>',
                ],
            ]],
        ]);
    }
}
