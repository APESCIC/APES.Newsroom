<?php

namespace Tests\Unit\Seo;

use App\Services\EditorJs\BodyTextExtractor;
use App\Services\Seo\MetaGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaGeneratorTest extends TestCase
{
    private MetaGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new MetaGenerator(new BodyTextExtractor);
    }

    #[Test]
    public function title_appends_site_suffix_and_trims_long_bases(): void
    {
        $this->assertSame(
            'Short title | APES Newsroom',
            $this->generator->title('Short title'),
        );

        $long = str_repeat('a', 80);
        $generated = $this->generator->title($long);
        $this->assertStringEndsWith(' | APES Newsroom', $generated);
        $this->assertLessThanOrEqual(60 + strlen(' | APES Newsroom'), mb_strlen($generated));
    }

    #[Test]
    public function description_prefers_excerpt_then_first_paragraph(): void
    {
        $this->assertSame(
            'From the excerpt.',
            $this->generator->description('From the excerpt.', [
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Body paragraph that should be ignored.']],
                ],
            ]),
        );

        $this->assertSame(
            'First body paragraph.',
            $this->generator->description(null, [
                'blocks' => [
                    ['type' => 'delimiter', 'data' => []],
                    ['type' => 'paragraph', 'data' => ['text' => 'First body paragraph.']],
                ],
            ]),
        );
    }

    #[Test]
    public function description_strips_html_and_caps_at_155(): void
    {
        $html = '<p>Hello <strong>world</strong> &amp; friends.</p>';
        $this->assertSame('Hello world & friends.', $this->generator->description($html));

        $long = str_repeat('word ', 50);
        $generated = $this->generator->description($long);
        $this->assertLessThanOrEqual(155, mb_strlen($generated));
        $this->assertStringEndsWith('…', $generated);
    }

    #[Test]
    public function for_editor_returns_both_fields(): void
    {
        $result = $this->generator->forEditor('Corridor update', 'A short excerpt.');
        $this->assertSame('Corridor update | APES Newsroom', $result['meta_title']);
        $this->assertSame('A short excerpt.', $result['meta_description']);
    }
}
