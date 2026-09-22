<?php

namespace Tests\Unit;

use App\Services\EditorJs\BlockRenderer;
use App\Services\EditorJs\BlockValidator;
use Tests\TestCase;

class BlockRendererTest extends TestCase
{
    public function test_gallery_video_and_audio_render_safe_html(): void
    {
        $document = (new BlockValidator)->validate([
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
                            [
                                'url' => 'https://cdn.example.com/b.jpg',
                                'alt' => 'B photo',
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
                        'caption' => 'Track',
                    ],
                ],
            ],
        ]);

        $html = (new BlockRenderer)->toHtml($document);

        $this->assertStringContainsString('class="gallery"', $html);
        $this->assertStringContainsString('src="https://cdn.example.com/a.jpg"', $html);
        $this->assertStringContainsString('alt="A photo"', $html);
        $this->assertStringContainsString('<video src="https://cdn.example.com/clip.mp4"', $html);
        $this->assertStringContainsString('poster="https://cdn.example.com/poster.jpg"', $html);
        $this->assertStringContainsString('aria-label="Clip"', $html);
        $this->assertStringContainsString('<audio src="https://cdn.example.com/track.mp3"', $html);
        $this->assertStringContainsString('aria-label="Track"', $html);
        $this->assertStringContainsString('>Album</figcaption>', $html);
        $this->assertStringNotContainsString('<li><figure>', $html);
        $this->assertStringContainsString('class="gallery-item-caption">First</span>', $html);
    }

    public function test_unknown_block_types_are_silently_dropped(): void
    {
        $html = (new BlockRenderer)->toHtml([
            'blocks' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Keep me']],
                ['type' => 'mystery', 'data' => ['text' => 'drop']],
            ],
        ]);

        $this->assertSame('<p>Keep me</p>', $html);
    }

    public function test_empty_media_urls_and_toggle_title_fail_closed(): void
    {
        $html = (new BlockRenderer)->toHtml([
            'blocks' => [
                [
                    'type' => 'gallery',
                    'data' => [
                        'items' => [
                            ['url' => '', 'alt' => 'Empty'],
                            ['url' => 'https://cdn.example.com/keep.jpg', 'alt' => 'Keep'],
                        ],
                    ],
                ],
                ['type' => 'video', 'data' => ['url' => '']],
                ['type' => 'audio', 'data' => ['url' => '']],
                ['type' => 'file', 'data' => ['url' => '']],
                ['type' => 'bookmark', 'data' => ['url' => '']],
                ['type' => 'toggle', 'data' => ['title' => '', 'content' => 'Hidden']],
                ['type' => 'product', 'data' => ['title' => '']],
            ],
        ]);

        $this->assertStringContainsString('src="https://cdn.example.com/keep.jpg"', $html);
        $this->assertStringNotContainsString('src=""', $html);
        $this->assertStringNotContainsString('<video', $html);
        $this->assertStringNotContainsString('<audio', $html);
        $this->assertStringNotContainsString('file-block', $html);
        $this->assertStringNotContainsString('bookmark', $html);
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('product', $html);
    }

    public function test_gallery_with_only_empty_urls_renders_nothing(): void
    {
        $html = (new BlockRenderer)->toHtml([
            'blocks' => [[
                'type' => 'gallery',
                'data' => [
                    'items' => [
                        ['url' => '', 'alt' => 'A'],
                        ['url' => '', 'alt' => 'B'],
                    ],
                    'caption' => 'Should not appear',
                ],
            ]],
        ]);

        $this->assertSame('', $html);
    }

    public function test_file_bookmark_product_and_toggle_render_safe_html(): void
    {
        $document = (new BlockValidator)->validate([
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

        $html = (new BlockRenderer)->toHtml($document);

        $this->assertStringContainsString('class="file-block"', $html);
        $this->assertStringContainsString('href="https://cdn.example.com/guide.pdf"', $html);
        $this->assertStringContainsString('class="bookmark"', $html);
        $this->assertStringContainsString('src="https://cdn.example.com/og.jpg"', $html);
        $this->assertStringContainsString('class="product"', $html);
        $this->assertStringContainsString('<p class="product-title"><strong>', $html);
        $this->assertStringNotContainsString('<h3 class="product-title">', $html);
        $this->assertStringContainsString('>£12</p>', $html);
        $this->assertStringContainsString('<details class="toggle">', $html);
        $this->assertStringContainsString('<summary>More info</summary>', $html);
        $this->assertStringContainsString('Details here', $html);
    }

    public function test_video_and_audio_default_accessible_names_without_caption(): void
    {
        $html = (new BlockRenderer)->toHtml([
            'blocks' => [
                ['type' => 'video', 'data' => ['url' => 'https://cdn.example.com/a.mp4']],
                ['type' => 'audio', 'data' => ['url' => 'https://cdn.example.com/a.mp3']],
            ],
        ]);

        $this->assertStringContainsString('aria-label="Video"', $html);
        $this->assertStringContainsString('aria-label="Audio"', $html);
    }

    public function test_markup_renders_sanitized_html_from_markdown(): void
    {
        $document = (new BlockValidator)->validate([
            'blocks' => [[
                'type' => 'markup',
                'data' => [
                    'format' => 'markdown',
                    'source' => "## Hello\n\nWorld",
                ],
            ]],
        ]);

        $html = (new BlockRenderer)->toHtml($document);

        $this->assertStringContainsString('class="markup-block"', $html);
        $this->assertStringContainsString('data-format="markdown"', $html);
        $this->assertStringContainsString('<h2>Hello</h2>', $html);
        $this->assertStringContainsString('<p>World</p>', $html);
        $this->assertStringContainsString($document['blocks'][0]['data']['html'], $html);
    }
}
