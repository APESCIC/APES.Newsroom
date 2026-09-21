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
}
