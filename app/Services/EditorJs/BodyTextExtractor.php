<?php

namespace App\Services\EditorJs;

/**
 * Extracts denormalized plain text from Editor.js documents for search indexing.
 */
class BodyTextExtractor
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function extract(array $document): string
    {
        $parts = [];

        foreach ($document['blocks'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $text = $this->blockText($block);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function blockText(array $block): string
    {
        $type = (string) ($block['type'] ?? '');
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        return match ($type) {
            'paragraph', 'header', 'callout' => $this->plain((string) ($data['text'] ?? '')),
            'quote' => trim($this->plain((string) ($data['text'] ?? '')).' '.$this->plain((string) ($data['caption'] ?? ''))),
            'list' => $this->listText($data),
            'image' => trim($this->plain((string) ($data['caption'] ?? '')).' '.$this->plain((string) ($data['alt'] ?? '')).' '.$this->plain((string) ($data['credit'] ?? ''))),
            'table' => $this->tableText($data),
            'gallery' => $this->galleryText($data),
            'video', 'audio', 'embed' => $this->plain((string) ($data['caption'] ?? '')),
            'file' => $this->plain((string) ($data['title'] ?? '')),
            'bookmark' => trim($this->plain((string) ($data['title'] ?? '')).' '.$this->plain((string) ($data['description'] ?? ''))),
            'product' => trim($this->plain((string) ($data['title'] ?? '')).' '.$this->plain((string) ($data['description'] ?? '')).' '.$this->plain((string) ($data['priceLabel'] ?? ''))),
            'toggle' => trim($this->plain((string) ($data['title'] ?? '')).' '.$this->plain((string) ($data['content'] ?? ''))),
            'markup', 'legacy' => $this->plain((string) ($data['html'] ?? '')),
            'linkTool' => $this->plain((string) ($data['meta']['title'] ?? $data['link'] ?? '')),
            'delimiter' => '',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function listText(array $data): string
    {
        $parts = [];

        foreach ($data['items'] ?? [] as $item) {
            if (is_array($item)) {
                $parts[] = $this->plain((string) ($item['content'] ?? $item['text'] ?? ''));
            } else {
                $parts[] = $this->plain((string) $item);
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function tableText(array $data): string
    {
        $parts = [];

        foreach ($data['content'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach ($row as $cell) {
                $parts[] = $this->plain((string) $cell);
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function galleryText(array $data): string
    {
        $parts = [$this->plain((string) ($data['caption'] ?? ''))];

        foreach ($data['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $parts[] = $this->plain((string) ($item['alt'] ?? ''));
            $parts[] = $this->plain((string) ($item['caption'] ?? ''));
        }

        return trim(implode(' ', array_filter($parts)));
    }

    private function plain(string $value): string
    {
        $stripped = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? '');
    }
}
