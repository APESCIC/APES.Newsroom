<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MailThemeContrastTest extends TestCase
{
    private const SURFACES = [
        'base' => '#E8F6F6',
        'card' => '#FFFFFF',
        'panel' => '#F4FBFB',
    ];

    private const TEXT_COLOURS = ['#0B2B2C', '#40676A', '#006E70'];

    private const ACCENT = '#00C4CC';

    public function test_every_text_colour_in_the_theme_is_an_approved_text_colour(): void
    {
        $allowed = [...self::TEXT_COLOURS, '#FFFFFF', '#8A4B0B'];

        foreach ($this->declarations('color') as [$selector, $value]) {
            $this->assertContains($value, $allowed, "{$selector} uses {$value} for text");
        }
    }

    public function test_the_accent_is_never_used_for_text(): void
    {
        foreach ($this->declarations('color') as [$selector, $value]) {
            $this->assertNotSame(self::ACCENT, $value, "{$selector} uses the accent for text");
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textOnSurface(): iterable
    {
        foreach (self::TEXT_COLOURS as $text) {
            foreach (self::SURFACES as $name => $surface) {
                yield "{$text} on {$name}" => [$text, $surface];
            }
        }
        yield 'ink on accent badge' => ['#0B2B2C', self::ACCENT];
        yield 'warn on test banner' => ['#8A4B0B', '#FFF6E5'];
    }

    #[DataProvider('textOnSurface')]
    public function test_text_meets_aa_on_every_surface(string $text, string $surface): void
    {
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($text, $surface));
    }

    public function test_button_fills_meet_aa_with_white_text(): void
    {
        $fills = array_column($this->declarations('background-color', '/^\.button-/'), 1);

        $this->assertNotEmpty($fills);
        foreach ($fills as $fill) {
            $this->assertGreaterThanOrEqual(4.5, $this->contrast('#FFFFFF', $fill), "white on {$fill}");
            $this->assertGreaterThanOrEqual(3.0, $this->contrast($fill, self::SURFACES['card']), "{$fill} button edge on card");
        }
    }

    public function test_borders_meet_three_to_one_except_the_decorative_accent(): void
    {
        $css = $this->css();
        preg_match_all('/border(?:-[a-z]+)?:\s*[^;]*?(#[0-9A-Fa-f]{6})/', $css, $matches);

        foreach (array_unique(array_map('strtoupper', $matches[1])) as $colour) {
            if ($colour === self::ACCENT) {
                continue;
            }
            $this->assertGreaterThanOrEqual(3.0, $this->contrast($colour, self::SURFACES['base']), "{$colour} border on base");
            $this->assertGreaterThanOrEqual(3.0, $this->contrast($colour, self::SURFACES['card']), "{$colour} border on card");
        }
    }

    /**
     * @return list<array{string, string}>
     */
    private function declarations(string $property, ?string $selectorPattern = null): array
    {
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $this->css(), $rules, PREG_SET_ORDER);

        $found = [];
        foreach ($rules as [, $selector, $body]) {
            $selector = trim(preg_replace('#/\*.*?\*/#s', '', $selector));
            if ($selectorPattern !== null && ! preg_match($selectorPattern, $selector)) {
                continue;
            }
            if (preg_match_all('/(?<![-\w])'.preg_quote($property, '/').':\s*(#[0-9A-Fa-f]{6})/', $body, $values)) {
                foreach ($values[1] as $value) {
                    $found[] = [$selector, strtoupper($value)];
                }
            }
        }

        return $found;
    }

    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/vendor/mail/html/themes/soft-glass.css');
    }

    private function contrast(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(
            function (string $pair): float {
                $c = hexdec($pair) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
