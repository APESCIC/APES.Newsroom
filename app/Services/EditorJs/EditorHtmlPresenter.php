<?php

namespace App\Services\EditorJs;

/**
 * Renders stored Editor.js blocks as TinyMCE editing HTML (issues #257, #258).
 *
 * Unlike BlockRenderer this is not public output: blocks TinyMCE cannot edit
 * become read-only placeholders that carry their original data so
 * HtmlToBlocksConverter can restore them unchanged.
 */
class EditorHtmlPresenter
{
    public const PLACEHOLDER_CLASS = 'nr-block-placeholder';

    /** @var list<string> */
    public const PLACEHOLDER_TYPES = [
        'callout', 'toggle', 'gallery', 'video', 'audio', 'file', 'bookmark',
        'product', 'embed', 'linkTool', 'markup', 'legacy',
    ];

    /** @var array<string, string> */
    private const PLACEHOLDER_LABELS = [
        'callout' => 'Callout',
        'toggle' => 'Toggle',
        'gallery' => 'Gallery',
        'video' => 'Video',
        'audio' => 'Audio',
        'file' => 'File download',
        'bookmark' => 'Bookmark',
        'product' => 'Product',
        'embed' => 'Embed',
        'linkTool' => 'Link preview',
        'markup' => 'Markdown or HTML snippet',
        'legacy' => 'Imported content',
    ];

    public function __construct(
        private readonly InlineHtmlSanitizer $inline = new InlineHtmlSanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     */
    public function toHtml(array $document): string
    {
        $html = '';

        foreach ($document['blocks'] ?? [] as $block) {
            if (is_array($block)) {
                $html .= $this->renderBlock($block);
            }
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        if (in_array($type, self::PLACEHOLDER_TYPES, true)) {
            return $this->placeholder($type, $data);
        }

        return match ($type) {
            'paragraph' => $this->renderParagraph($data),
            'header' => $this->renderHeader($data),
            'list' => $this->renderList($data),
            'quote' => $this->renderQuote($data),
            'image' => $this->renderImage($data),
            'table' => $this->renderTable($data),
            'delimiter' => '<hr>',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderParagraph(array $data): string
    {
        $text = $this->inlineHtml($data['text'] ?? '');

        return '<p>'.($text === '' ? '&nbsp;' : $text).'</p>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderHeader(array $data): string
    {
        $level = min(max((int) ($data['level'] ?? 2), 2), 4);

        return "<h{$level}>".$this->inlineHtml($data['text'] ?? '')."</h{$level}>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderList(array $data): string
    {
        $tag = ($data['style'] ?? '') === 'ordered' ? 'ol' : 'ul';
        $items = '';

        foreach ($data['items'] ?? [] as $item) {
            $items .= '<li>'.$this->inlineHtml(is_array($item) ? ($item['content'] ?? $item['text'] ?? '') : $item).'</li>';
        }

        return "<{$tag}>{$items}</{$tag}>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderQuote(array $data): string
    {
        $caption = trim((string) ($data['caption'] ?? ''));
        $cite = $caption !== '' ? '<p><cite>'.e($caption).'</cite></p>' : '';

        return '<blockquote><p>'.$this->inlineHtml($data['text'] ?? '').'</p>'.$cite.'</blockquote>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderImage(array $data): string
    {
        $url = e((string) ($data['file']['url'] ?? $data['url'] ?? ''));
        $alt = e((string) ($data['alt'] ?? ''));
        $credit = (string) ($data['credit'] ?? '');
        $creditAttribute = $credit !== '' ? ' data-credit="'.e($credit).'"' : '';
        $caption = '<figcaption>'.e((string) ($data['caption'] ?? '')).'</figcaption>';

        return "<figure class=\"image\"{$creditAttribute}><img src=\"{$url}\" alt=\"{$alt}\">{$caption}</figure>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderTable(array $data): string
    {
        $withHeadings = (bool) ($data['withHeadings'] ?? false);
        $head = '';
        $body = '';

        foreach (array_values(is_array($data['content'] ?? null) ? $data['content'] : []) as $rowIndex => $row) {
            if (! is_array($row)) {
                continue;
            }

            $isHeading = $withHeadings && $rowIndex === 0;
            $tag = $isHeading ? 'th' : 'td';
            $cells = '';

            foreach ($row as $cell) {
                $cells .= "<{$tag}>".$this->inlineHtml($cell)."</{$tag}>";
            }

            if ($isHeading) {
                $head = "<thead><tr>{$cells}</tr></thead>";
            } else {
                $body .= "<tr>{$cells}</tr>";
            }
        }

        return "<table>{$head}<tbody>{$body}</tbody></table>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function placeholder(string $type, array $data): string
    {
        $label = self::PLACEHOLDER_LABELS[$type];
        $preview = $this->preview($type, $data);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '{}';
        $ariaLabel = "{$label} block. Read-only here; edit it in Editor.js.";

        return '<div class="'.self::PLACEHOLDER_CLASS.'" contenteditable="false"'
            .' data-block-type="'.e($type).'" data-block="'.e($json).'"'
            .' role="group" aria-label="'.e($ariaLabel).'">'
            .'<strong>'.e($label).'</strong>'
            .($preview !== '' ? ' '.e($preview) : '')
            .'</div>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function preview(string $type, array $data): string
    {
        $text = match ($type) {
            'callout' => $data['text'] ?? '',
            'toggle' => $data['title'] ?? '',
            'gallery' => count(is_array($data['items'] ?? null) ? $data['items'] : []).' image(s)',
            'video', 'audio' => ($data['caption'] ?? '') !== '' ? $data['caption'] : ($data['url'] ?? ''),
            'file' => $data['title'] ?? $data['url'] ?? '',
            'bookmark' => $data['title'] ?? $data['url'] ?? '',
            'product' => $data['title'] ?? '',
            'embed' => trim(ucfirst((string) ($data['service'] ?? '')).' '.($data['source'] ?? '')),
            'linkTool' => $data['meta']['title'] ?? $data['link'] ?? '',
            'markup' => ($data['format'] ?? '') === 'html' ? 'HTML' : 'Markdown',
            'legacy' => $data['note'] ?? '',
            default => '',
        };

        $plain = trim(html_entity_decode(strip_tags(is_scalar($text) ? (string) $text : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return mb_strlen($plain) > 120 ? mb_substr($plain, 0, 117).'...' : $plain;
    }

    private function inlineHtml(mixed $text): string
    {
        return is_scalar($text) ? $this->inline->sanitize((string) $text) : '';
    }
}
