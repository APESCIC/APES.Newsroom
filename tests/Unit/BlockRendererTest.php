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
}
