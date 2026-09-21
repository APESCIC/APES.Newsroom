<?php

namespace App\Services\EditorJs;

/**
 * Renders validated Editor.js blocks to safe HTML for public display.
 */
class BlockRenderer
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function toHtml(array $document): string
    {
        $html = '';

        foreach ($document['blocks'] ?? [] as $block) {
            $html .= $this->renderBlock($block);
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function renderBlock(array $block): string
    {
        $type = $block['type'] ?? '';
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        return match ($type) {
            'paragraph' => '<p>'.e($data['text'] ?? '').'</p>',
            'header' => '<h'.(int) ($data['level'] ?? 2).'>'.e($data['text'] ?? '').'</h'.(int) ($data['level'] ?? 2).'>',
            'list' => $this->renderList($data),
            'quote' => '<blockquote><p>'.e($data['text'] ?? '').'</p>'.(($data['caption'] ?? '') ? '<cite>'.e($data['caption']).'</cite>' : '').'</blockquote>',
            'image' => $this->renderImage($data),
            'table' => $this->renderTable($data),
            'delimiter' => '<hr />',
            'callout' => '<aside class="callout">'.e($data['text'] ?? '').'</aside>',
            'gallery' => $this->renderGallery($data),
            'video' => $this->renderVideo($data),
            'audio' => $this->renderAudio($data),
            'file' => $this->renderFile($data),
            'bookmark' => $this->renderBookmark($data),
            'product' => $this->renderProduct($data),
            'toggle' => $this->renderToggle($data),
            'markup' => $this->renderMarkup($data),
            'linkTool' => '<p><a href="'.e($data['link'] ?? '').'" rel="noopener noreferrer">'.e($data['meta']['title'] ?? $data['link'] ?? '').'</a></p>',
            'embed' => $this->renderEmbed($data),
            'legacy' => $this->renderLegacy($data),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderMarkup(array $data): string
    {
        $html = (string) ($data['html'] ?? '');

        if ($html === '') {
            return '';
        }

        // Already sanitized by BlockValidator / MarkupSanitizer — do not escape tags.
        return '<div class="markup-block" data-format="'.e((string) ($data['format'] ?? '')).'">'.$html.'</div>';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderFile(array $data): string
    {
        $url = e($data['url'] ?? '');

        if ($url === '') {
            return '';
        }

        $label = e($data['title'] ?? $data['url'] ?? 'Download');
        $size = ($data['size'] ?? '') !== ''
            ? ' <span class="file-size">'.e((string) $data['size']).'</span>'
            : '';

        return "<p class=\"file-block\"><a href=\"{$url}\" rel=\"noopener noreferrer\">{$label}</a>{$size}</p>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderBookmark(array $data): string
    {
        $url = e($data['url'] ?? '');

        if ($url === '') {
            return '';
        }

        $title = e($data['title'] ?? $data['url'] ?? '');
        $description = ($data['description'] ?? '') !== ''
            ? '<p class="bookmark-description">'.e((string) $data['description']).'</p>'
            : '';
        $image = ($data['image'] ?? '') !== ''
            ? '<img src="'.e((string) $data['image']).'" alt="" loading="lazy" />'
            : '';

        return "<aside class=\"bookmark\"><a href=\"{$url}\" rel=\"noopener noreferrer\">{$image}<span class=\"bookmark-title\">{$title}</span></a>{$description}</aside>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderProduct(array $data): string
    {
        $title = e($data['title'] ?? '');

        if ($title === '') {
            return '';
        }

        $description = ($data['description'] ?? '') !== ''
            ? '<p>'.e((string) $data['description']).'</p>'
            : '';
        $price = ($data['priceLabel'] ?? '') !== ''
            ? '<p class="product-price">'.e((string) $data['priceLabel']).'</p>'
            : '';

        $heading = $title;

        if (($data['url'] ?? '') !== '') {
            $heading = '<a href="'.e((string) $data['url']).'" rel="noopener noreferrer">'.$title.'</a>';
        }

        return "<aside class=\"product\"><h3 class=\"product-title\">{$heading}</h3>{$description}{$price}</aside>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderToggle(array $data): string
    {
        $title = e($data['title'] ?? '');
        $content = e($data['content'] ?? '');

        return "<details class=\"toggle\"><summary>{$title}</summary><div class=\"toggle-content\">{$content}</div></details>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderGallery(array $data): string
    {
        $itemsHtml = '';

        foreach ($data['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = e($item['url'] ?? '');
            $alt = e($item['alt'] ?? '');
            $caption = ($item['caption'] ?? '') !== ''
                ? '<figcaption>'.e((string) $item['caption']).'</figcaption>'
                : '';

            $itemsHtml .= "<li><figure><img src=\"{$url}\" alt=\"{$alt}\" loading=\"lazy\" />{$caption}</figure></li>";
        }

        if ($itemsHtml === '') {
            return '';
        }

        $caption = ($data['caption'] ?? '') !== ''
            ? '<figcaption>'.e((string) $data['caption']).'</figcaption>'
            : '';

        return "<figure class=\"gallery\"><ul class=\"gallery-items\">{$itemsHtml}</ul>{$caption}</figure>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderVideo(array $data): string
    {
        $url = e($data['url'] ?? '');

        if ($url === '') {
            return '';
        }

        $poster = ($data['poster'] ?? '') !== ''
            ? ' poster="'.e((string) $data['poster']).'"'
            : '';
        $caption = ($data['caption'] ?? '') !== ''
            ? '<figcaption>'.e((string) $data['caption']).'</figcaption>'
            : '';

        return "<figure class=\"video\"><video src=\"{$url}\"{$poster} controls preload=\"metadata\"></video>{$caption}</figure>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderAudio(array $data): string
    {
        $url = e($data['url'] ?? '');

        if ($url === '') {
            return '';
        }

        $caption = ($data['caption'] ?? '') !== ''
            ? '<figcaption>'.e((string) $data['caption']).'</figcaption>'
            : '';

        return "<figure class=\"audio\"><audio src=\"{$url}\" controls preload=\"metadata\"></audio>{$caption}</figure>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderList(array $data): string
    {
        $tag = ($data['style'] ?? '') === 'ordered' ? 'ol' : 'ul';
        $items = '';

        foreach ($data['items'] ?? [] as $item) {
            $items .= '<li>'.e(is_array($item) ? (string) ($item['content'] ?? $item['text'] ?? '') : (string) $item).'</li>';
        }

        return "<{$tag}>{$items}</{$tag}>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderImage(array $data): string
    {
        $url = e($data['file']['url'] ?? $data['url'] ?? '');
        $alt = e($data['alt'] ?? '');
        $caption = ($data['caption'] ?? '') ? '<figcaption>'.e($data['caption']).'</figcaption>' : '';
        $credit = ($data['credit'] ?? '') ? '<p class="image-credit">'.e($data['credit']).'</p>' : '';

        return "<figure><img src=\"{$url}\" alt=\"{$alt}\" loading=\"lazy\" />{$caption}{$credit}</figure>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderTable(array $data): string
    {
        $rows = $data['content'] ?? [];
        $withHeadings = (bool) ($data['withHeadings'] ?? false);
        $body = '';

        foreach ($rows as $rowIndex => $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = '';
            $tag = ($withHeadings && $rowIndex === 0) ? 'th' : 'td';

            foreach ($row as $cell) {
                $cells .= "<{$tag}>".e((string) $cell)."</{$tag}>";
            }

            $body .= "<tr>{$cells}</tr>";
        }

        return "<table>{$body}</table>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderEmbed(array $data): string
    {
        $src = e($data['embed'] ?? $data['source'] ?? '');
        $width = (int) ($data['width'] ?? 580);
        $height = (int) ($data['height'] ?? 320);
        $caption = ($data['caption'] ?? '') ? '<figcaption>'.e($data['caption']).'</figcaption>' : '';

        return "<figure class=\"embed\"><iframe src=\"{$src}\" width=\"{$width}\" height=\"{$height}\" loading=\"lazy\" referrerpolicy=\"no-referrer\" allowfullscreen title=\"Embedded content\"></iframe>{$caption}</figure>";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderLegacy(array $data): string
    {
        $html = (string) ($data['html'] ?? '');
        $note = e($data['note'] ?? 'Imported legacy content');

        return '<div class="legacy-block" data-needs-review="true"><p class="legacy-note">'.$note.'</p>'.$html.'</div>';
    }
}
