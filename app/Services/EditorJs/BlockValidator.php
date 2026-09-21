<?php

namespace App\Services\EditorJs;

use Illuminate\Validation\ValidationException;

/**
 * Server-side Editor.js block allowlist and validation (issue #5).
 *
 * Client sanitization is convenience only — every save and render path
 * must pass through this validator.
 */
class BlockValidator
{
    /** @var array<int, string> */
    private const ALLOWED_TYPES = [
        'paragraph',
        'header',
        'list',
        'quote',
        'image',
        'table',
        'delimiter',
        'callout',
        'gallery',
        'video',
        'audio',
        'file',
        'bookmark',
        'product',
        'toggle',
        'linkTool',
        'embed',
        'legacy',
    ];

    /** @var array<int, string> */
    private const APPROVED_EMBED_SERVICES = [
        'youtube',
        'vimeo',
        'twitter',
        'instagram',
        'codepen',
    ];

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function validate(array $document): array
    {
        if (! isset($document['blocks']) || ! is_array($document['blocks'])) {
            throw ValidationException::withMessages([
                'content' => 'Editor.js document must contain a blocks array.',
            ]);
        }

        $blocks = [];

        foreach ($document['blocks'] as $index => $block) {
            $blocks[] = $this->validateBlock($block, $index);
        }

        return [
            'time' => $document['time'] ?? now()->getTimestampMs(),
            'blocks' => $blocks,
            'version' => $document['version'] ?? '2.29.0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBlock(mixed $block, int $index): array
    {
        if (! is_array($block) || ! isset($block['type'], $block['data'])) {
            throw ValidationException::withMessages([
                'content' => "Block at index {$index} is malformed.",
            ]);
        }

        $type = (string) $block['type'];

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw ValidationException::withMessages([
                'content' => "Block type '{$type}' is not allowed.",
            ]);
        }

        $data = $block['data'];

        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'content' => "Block data at index {$index} must be an object.",
            ]);
        }

        return match ($type) {
            'paragraph' => ['type' => $type, 'data' => $this->validateParagraph($data, $index)],
            'header' => ['type' => $type, 'data' => $this->validateHeader($data, $index)],
            'list' => ['type' => $type, 'data' => $this->validateList($data, $index)],
            'quote' => ['type' => $type, 'data' => $this->validateQuote($data, $index)],
            'image' => ['type' => $type, 'data' => $this->validateImage($data, $index)],
            'table' => ['type' => $type, 'data' => $this->validateTable($data, $index)],
            'delimiter' => ['type' => $type, 'data' => new \stdClass],
            'callout' => ['type' => $type, 'data' => $this->validateCallout($data, $index)],
            'gallery' => ['type' => $type, 'data' => $this->validateGallery($data, $index)],
            'video' => ['type' => $type, 'data' => $this->validateVideo($data, $index)],
            'audio' => ['type' => $type, 'data' => $this->validateAudio($data, $index)],
            'file' => ['type' => $type, 'data' => $this->validateFile($data, $index)],
            'bookmark' => ['type' => $type, 'data' => $this->validateBookmark($data, $index)],
            'product' => ['type' => $type, 'data' => $this->validateProduct($data, $index)],
            'toggle' => ['type' => $type, 'data' => $this->validateToggle($data, $index)],
            'linkTool' => ['type' => $type, 'data' => $this->validateLink($data, $index)],
            'embed' => ['type' => $type, 'data' => $this->validateEmbed($data, $index)],
            'legacy' => ['type' => $type, 'data' => $this->validateLegacy($data, $index)],
            default => throw ValidationException::withMessages([
                'content' => "Block type '{$type}' is not allowed.",
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validateParagraph(array $data, int $index): array
    {
        return ['text' => $this->sanitizeText($data['text'] ?? '', $index, 'paragraph')];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateHeader(array $data, int $index): array
    {
        $level = (int) ($data['level'] ?? 2);

        if (! in_array($level, [2, 3, 4], true)) {
            throw ValidationException::withMessages([
                'content' => "Header at index {$index} must be level 2, 3, or 4.",
            ]);
        }

        return [
            'text' => $this->sanitizeText($data['text'] ?? '', $index, 'header'),
            'level' => $level,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateList(array $data, int $index): array
    {
        $style = ($data['style'] ?? 'unordered') === 'ordered' ? 'ordered' : 'unordered';
        $items = $data['items'] ?? [];

        if (! is_array($items)) {
            throw ValidationException::withMessages([
                'content' => "List at index {$index} must have items array.",
            ]);
        }

        return [
            'style' => $style,
            'items' => array_map(
                function ($item) use ($index) {
                    if (is_array($item)) {
                        return $this->sanitizeText((string) ($item['content'] ?? $item['text'] ?? ''), $index, 'list item');
                    }

                    return $this->sanitizeText((string) $item, $index, 'list item');
                },
                $items
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validateQuote(array $data, int $index): array
    {
        return [
            'text' => $this->sanitizeText($data['text'] ?? '', $index, 'quote'),
            'caption' => $this->sanitizeText($data['caption'] ?? '', $index, 'quote caption'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateImage(array $data, int $index): array
    {
        $url = (string) ($data['file']['url'] ?? $data['url'] ?? '');

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'content' => "Image at index {$index} requires a valid URL.",
            ]);
        }

        $alt = $this->sanitizeText($data['alt'] ?? '', $index, 'image alt');

        if ($alt === '') {
            throw ValidationException::withMessages([
                'content' => "Image at index {$index} requires alt text.",
            ]);
        }

        return [
            'file' => ['url' => $url],
            'alt' => $alt,
            'caption' => $this->sanitizeText($data['caption'] ?? '', $index, 'caption'),
            'credit' => $this->sanitizeText($data['credit'] ?? '', $index, 'credit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateTable(array $data, int $index): array
    {
        $content = $data['content'] ?? [];

        if (! is_array($content) || $content === []) {
            throw ValidationException::withMessages([
                'content' => "Table at index {$index} requires content rows.",
            ]);
        }

        $rows = [];

        foreach ($content as $rowIndex => $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'content' => "Table row {$rowIndex} at index {$index} is malformed.",
                ]);
            }

            $rows[] = array_map(
                fn ($cell) => $this->sanitizeText((string) $cell, $index, 'table cell'),
                $row
            );
        }

        return [
            'withHeadings' => (bool) ($data['withHeadings'] ?? false),
            'content' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validateCallout(array $data, int $index): array
    {
        return [
            'text' => $this->sanitizeText($data['text'] ?? '', $index, 'callout'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateGallery(array $data, int $index): array
    {
        $rawItems = $data['items'] ?? [];

        if (! is_array($rawItems) || $rawItems === []) {
            throw ValidationException::withMessages([
                'content' => "Gallery at index {$index} requires at least one item.",
            ]);
        }

        $items = [];

        foreach ($rawItems as $itemIndex => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    'content' => "Gallery item {$itemIndex} at index {$index} is malformed.",
                ]);
            }

            $url = $this->requireHttpUrl((string) ($item['url'] ?? ''), $index, "gallery item {$itemIndex} URL");
            $alt = $this->sanitizeText($item['alt'] ?? '', $index, "gallery item {$itemIndex} alt");

            if ($alt === '') {
                throw ValidationException::withMessages([
                    'content' => "Gallery item {$itemIndex} at index {$index} requires alt text.",
                ]);
            }

            $normalized = [
                'url' => $url,
                'alt' => $alt,
            ];

            $caption = $this->sanitizeText($item['caption'] ?? '', $index, "gallery item {$itemIndex} caption");

            if ($caption !== '') {
                $normalized['caption'] = $caption;
            }

            $items[] = $normalized;
        }

        $result = ['items' => $items];
        $caption = $this->sanitizeText($data['caption'] ?? '', $index, 'gallery caption');

        if ($caption !== '') {
            $result['caption'] = $caption;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateVideo(array $data, int $index): array
    {
        $result = [
            'url' => $this->requireHttpUrl((string) ($data['url'] ?? ''), $index, 'video URL'),
        ];

        $caption = $this->sanitizeText($data['caption'] ?? '', $index, 'video caption');

        if ($caption !== '') {
            $result['caption'] = $caption;
        }

        $poster = trim((string) ($data['poster'] ?? ''));

        if ($poster !== '') {
            $result['poster'] = $this->requireHttpUrl($poster, $index, 'video poster URL');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateAudio(array $data, int $index): array
    {
        $result = [
            'url' => $this->requireHttpUrl((string) ($data['url'] ?? ''), $index, 'audio URL'),
        ];

        $caption = $this->sanitizeText($data['caption'] ?? '', $index, 'audio caption');

        if ($caption !== '') {
            $result['caption'] = $caption;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateFile(array $data, int $index): array
    {
        $result = [
            'url' => $this->requireHttpUrl((string) ($data['url'] ?? ''), $index, 'file URL'),
        ];

        $title = $this->sanitizeText($data['title'] ?? '', $index, 'file title');

        if ($title !== '') {
            $result['title'] = $title;
        }

        $size = $this->sanitizeText($data['size'] ?? '', $index, 'file size');

        if ($size !== '') {
            $result['size'] = $size;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateBookmark(array $data, int $index): array
    {
        $result = [
            'url' => $this->requireHttpUrl((string) ($data['url'] ?? ''), $index, 'bookmark URL'),
        ];

        $title = $this->sanitizeText($data['title'] ?? '', $index, 'bookmark title');

        if ($title !== '') {
            $result['title'] = $title;
        }

        $description = $this->sanitizeText($data['description'] ?? '', $index, 'bookmark description');

        if ($description !== '') {
            $result['description'] = $description;
        }

        $image = trim((string) ($data['image'] ?? ''));

        if ($image !== '') {
            $result['image'] = $this->requireHttpUrl($image, $index, 'bookmark image URL');
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateProduct(array $data, int $index): array
    {
        $title = $this->sanitizeText($data['title'] ?? '', $index, 'product title');

        if ($title === '') {
            throw ValidationException::withMessages([
                'content' => "Product at index {$index} requires a title.",
            ]);
        }

        $result = ['title' => $title];

        $description = $this->sanitizeText($data['description'] ?? '', $index, 'product description');

        if ($description !== '') {
            $result['description'] = $description;
        }

        $url = trim((string) ($data['url'] ?? ''));

        if ($url !== '') {
            $result['url'] = $this->requireHttpUrl($url, $index, 'product URL');
        }

        $priceLabel = $this->sanitizeText($data['priceLabel'] ?? '', $index, 'product price label');

        if ($priceLabel !== '') {
            $result['priceLabel'] = $priceLabel;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validateToggle(array $data, int $index): array
    {
        return [
            'title' => $this->sanitizeText($data['title'] ?? '', $index, 'toggle title'),
            'content' => $this->sanitizeText($data['content'] ?? '', $index, 'toggle content'),
        ];
    }

    private function requireHttpUrl(string $url, int $index, string $context): string
    {
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'content' => "{$context} at index {$index} must be a valid URL.",
            ]);
        }

        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages([
                'content' => "{$context} at index {$index} must use http or https.",
            ]);
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateLink(array $data, int $index): array
    {
        $url = (string) ($data['link'] ?? '');

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'content' => "Link at index {$index} requires a valid URL.",
            ]);
        }

        return [
            'link' => $url,
            'meta' => [
                'title' => $this->sanitizeText($data['meta']['title'] ?? '', $index, 'link title'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateEmbed(array $data, int $index): array
    {
        $service = strtolower((string) ($data['service'] ?? ''));
        $source = (string) ($data['source'] ?? $data['embed'] ?? '');

        if (! in_array($service, self::APPROVED_EMBED_SERVICES, true)) {
            throw ValidationException::withMessages([
                'content' => "Embed service at index {$index} is not approved.",
            ]);
        }

        if ($source === '' || ! filter_var($source, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'content' => "Embed at index {$index} requires a valid source URL.",
            ]);
        }

        return [
            'service' => $service,
            'source' => $source,
            'embed' => filter_var((string) ($data['embed'] ?? $source), FILTER_VALIDATE_URL) ?: $source,
            'caption' => $this->sanitizeText($data['caption'] ?? '', $index, 'embed caption'),
            'width' => min(max((int) ($data['width'] ?? 580), 100), 1200),
            'height' => min(max((int) ($data['height'] ?? 320), 100), 900),
        ];
    }

    /**
     * Sanitized HTML from Ghost import for human review — never executed as script.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateLegacy(array $data, int $index): array
    {
        $html = (string) ($data['html'] ?? '');

        if (strlen($html) > 100000) {
            throw ValidationException::withMessages([
                'content' => "Legacy block at index {$index} exceeds maximum length.",
            ]);
        }

        if (preg_match('/<script|javascript:|on\w+=/i', $html)) {
            throw ValidationException::withMessages([
                'content' => "Disallowed content in legacy block at index {$index}.",
            ]);
        }

        return [
            'html' => strip_tags($html, '<p><br><strong><em><ul><ol><li><a><h2><h3><h4><blockquote><img><figure><figcaption><table><thead><tbody><tr><th><td><hr>'),
            'needs_review' => true,
            'note' => $this->sanitizeText($data['note'] ?? 'Imported legacy HTML', $index, 'legacy note'),
        ];
    }

    private function sanitizeText(string $text, int $index, string $context): string
    {
        if (strlen($text) > 10000) {
            throw ValidationException::withMessages([
                'content' => "Text in {$context} at index {$index} exceeds maximum length.",
            ]);
        }

        if (preg_match('/<script|javascript:/i', $text)) {
            throw ValidationException::withMessages([
                'content' => "Disallowed content in {$context} at index {$index}.",
            ]);
        }

        return strip_tags($text);
    }
}
