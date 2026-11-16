<?php

namespace App\Services\Seo;

use App\Services\EditorJs\BodyTextExtractor;

/**
 * Automatic SEO title and description defaults for public pages and the staff editor.
 */
class MetaGenerator
{
    public const TITLE_MAX = 60;

    public const DESCRIPTION_MAX = 155;

    public const SITE_SUFFIX = ' | APES Newsroom';

    public function __construct(
        private readonly BodyTextExtractor $bodyText,
    ) {}

    public function title(?string $title): string
    {
        $base = $this->plain($title);
        if ($base === '') {
            $base = 'APES Newsroom';
        }

        $suffix = self::SITE_SUFFIX;
        $maxBase = self::TITLE_MAX;

        if (mb_strlen($base) > $maxBase) {
            $base = rtrim(mb_substr($base, 0, $maxBase - 1)).'…';
        }

        $combined = $base.$suffix;
        if (mb_strlen($combined) <= self::TITLE_MAX + mb_strlen($suffix)) {
            return $combined;
        }

        // Keep the brand suffix; trim the base further if needed for very long suffixes.
        $allowed = max(1, self::TITLE_MAX + mb_strlen($suffix) - mb_strlen($suffix));

        return rtrim(mb_substr($base, 0, $allowed)).$suffix;
    }

    /**
     * @param  array<string, mixed>|null  $content  Editor.js document
     */
    public function description(?string $excerpt, ?array $content = null): string
    {
        $text = $this->plain($excerpt);

        if ($text === '' && is_array($content)) {
            $text = $this->firstParagraph($content);
        }

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= self::DESCRIPTION_MAX) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::DESCRIPTION_MAX - 1)).'…';
    }

    /**
     * Live editor defaults: title and description from the draft fields.
     *
     * @param  array<string, mixed>|null  $content
     * @return array{meta_title: string, meta_description: string}
     */
    public function forEditor(?string $title, ?string $excerpt, ?array $content = null): array
    {
        return [
            'meta_title' => $this->title($title),
            'meta_description' => $this->description($excerpt, $content),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function firstParagraph(array $content): string
    {
        foreach ($content['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = (string) ($block['type'] ?? '');
            if (! in_array($type, ['paragraph', 'header', 'callout', 'quote'], true)) {
                continue;
            }

            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $text = $this->plain((string) ($data['text'] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return $this->plain($this->bodyText->extract($content));
    }

    private function plain(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $stripped = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? '');
    }
}
