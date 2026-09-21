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
        $this->assertStringContainsString('<audio src="https://cdn.example.com/track.mp3"', $html);
        $this->assertStringContainsString('>Album</figcaption>', $html);
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
        $this->assertStringContainsString('>£12</p>', $html);
        $this->assertStringContainsString('<details class="toggle">', $html);
        $this->assertStringContainsString('<summary>More info</summary>', $html);
        $this->assertStringContainsString('Details here', $html);
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
