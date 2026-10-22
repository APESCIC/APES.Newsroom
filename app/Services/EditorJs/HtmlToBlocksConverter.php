<?php

namespace App\Services\EditorJs;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Validation\ValidationException;

/**
 * Converts TinyMCE editor HTML back into Editor.js blocks (issues #257, #258).
 *
 * Read-only placeholders produced by EditorHtmlPresenter are restored verbatim
 * from their data-block JSON. The result must still pass BlockValidator.
 */
class HtmlToBlocksConverter
{
    /** @var list<string> */
    private const INLINE_TAGS = ['strong', 'b', 'em', 'i', 'code', 'a', 'br', 'span', 'u', 's', 'sub', 'sup', 'mark', 'small', 'abbr', 'cite', 'q', 'kbd', 'time'];

    /** @var list<string> */
    private const DROPPED_TAGS = ['script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed', 'svg', 'math', 'textarea', 'select', 'button', 'input', 'form', 'video', 'audio', 'canvas', 'head', 'title', 'meta', 'link'];

    /** @var list<string> */
    private const CONTAINER_TAGS = ['div', 'section', 'article', 'main', 'aside', 'header', 'footer', 'nav', 'body', 'html', 'center'];

    /** @var list<array{type: string, data: array<string, mixed>}> */
    private array $blocks = [];

    private string $pendingInline = '';

    public function __construct(
        private readonly InlineHtmlSanitizer $inline = new InlineHtmlSanitizer,
    ) {}

    /**
     * @return list<array{type: string, data: array<string, mixed>}>
     */
    public function convert(string $html): array
    {
        $this->blocks = [];
        $this->pendingInline = '';

        if (trim($html) !== '') {
            $document = HTMLDocument::createEmpty();
            $root = $document->createElement('div');
            $root->innerHTML = $html;
            $this->walkChildren($root);
            $this->flushInline();
        }

        if ($this->blocks === []) {
            return [['type' => 'paragraph', 'data' => ['text' => '']]];
        }

        return $this->blocks;
    }

    private function walkChildren(Node $parent): void
    {
        foreach ($parent->childNodes as $child) {
            $this->walk($child);
        }
    }

    private function walk(Node $node): void
    {
        if ($node instanceof Text) {
            $this->pendingInline .= $this->escape($node->textContent ?? '');

            return;
        }

        if (! $node instanceof Element) {
            return;
        }

        if ($node->hasAttribute('data-block-type')) {
            $this->flushInline();
            $this->blocks[] = $this->restorePlaceholder($node);

            return;
        }

        $tag = strtolower($node->localName);

        if (in_array($tag, self::DROPPED_TAGS, true)) {
            return;
        }

        if (in_array($tag, self::INLINE_TAGS, true)) {
            $this->pendingInline .= $this->outerHtml($node);

            return;
        }

        $this->flushInline();

        match (true) {
            $tag === 'p' => $this->convertParagraph($node),
            (bool) preg_match('/^h[1-6]$/', $tag) => $this->blocks[] = [
                'type' => 'header',
                'data' => [
                    'text' => $this->inlineHtml($node),
                    'level' => min(max((int) substr($tag, 1), 2), 4),
                ],
            ],
            $tag === 'ul', $tag === 'ol' => $this->blocks[] = $this->convertList($node),
            $tag === 'blockquote' => $this->blocks[] = $this->convertQuote($node),
            $tag === 'figure' => $this->convertFigure($node),
            $tag === 'img' => $this->appendImage($node, '', ''),
            $tag === 'table' => $this->convertTable($node),
            $tag === 'hr' => $this->blocks[] = ['type' => 'delimiter', 'data' => []],
            in_array($tag, self::CONTAINER_TAGS, true) => $this->walkChildren($node),
            default => $this->appendParagraph($this->escape($this->plainText($node))),
        };

        $this->flushInline();
    }

    private function flushInline(): void
    {
        $html = $this->pendingInline;
        $this->pendingInline = '';

        if ($this->isBlank($html)) {
            return;
        }

        $this->appendParagraph($this->inline->sanitize(trim($html)));
    }

    private function appendParagraph(string $text): void
    {
        $this->blocks[] = ['type' => 'paragraph', 'data' => ['text' => $text]];
    }

    private function convertParagraph(Element $paragraph): void
    {
        // A <p> with no child nodes is a parser artefact (for example around a
        // block-level placeholder); deliberate blank lines arrive as <p>&nbsp;</p>.
        if (! $paragraph->hasChildNodes()) {
            return;
        }

        $media = [];

        foreach ($paragraph->querySelectorAll('img, [data-block-type]') as $element) {
            $media[] = $element;
        }

        if ($media === []) {
            $this->appendParagraph($this->inlineHtml($paragraph));

            return;
        }

        foreach ($media as $element) {
            $element->remove();
        }

        $text = $this->inlineHtml($paragraph);

        if (! $this->isBlank($text)) {
            $this->appendParagraph($text);
        }

        foreach ($media as $element) {
            $this->walk($element);
        }
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function convertList(Element $list): array
    {
        $items = [];
        $this->collectListItems($list, $items);

        return [
            'type' => 'list',
            'data' => [
                'style' => strtolower($list->localName) === 'ol' ? 'ordered' : 'unordered',
                'items' => $items,
            ],
        ];
    }

    /**
     * Nested lists are flattened in document order; BlockValidator stores flat items.
     *
     * @param  list<string>  $items
     */
    private function collectListItems(Element $list, array &$items): void
    {
        foreach ($this->childElements($list) as $item) {
            if (strtolower($item->localName) !== 'li') {
                continue;
            }

            $nested = [];

            foreach ($this->childElements($item) as $child) {
                if (in_array(strtolower($child->localName), ['ul', 'ol'], true)) {
                    $nested[] = $child;
                }
            }

            foreach ($nested as $child) {
                $child->remove();
            }

            $items[] = $this->inlineHtml($item);

            foreach ($nested as $child) {
                $this->collectListItems($child, $items);
            }
        }
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function convertQuote(Element $quote): array
    {
        $caption = '';

        foreach ($quote->querySelectorAll('cite') as $cite) {
            $caption = trim($caption.' '.$this->plainText($cite));
            $cite->remove();
        }

        $lines = [];
        $hasBlockChildren = false;

        foreach ($this->childElements($quote) as $child) {
            if (in_array(strtolower($child->localName), ['p', 'div', 'footer'], true)) {
                $hasBlockChildren = true;
                $line = $this->inlineHtml($child);

                if (! $this->isBlank($line)) {
                    $lines[] = $line;
                }
            }
        }

        $text = $hasBlockChildren ? implode('<br>', $lines) : $this->inlineHtml($quote);

        return ['type' => 'quote', 'data' => ['text' => $text, 'caption' => $caption]];
    }

    private function convertFigure(Element $figure): void
    {
        $image = $figure->querySelector('img');

        if ($image === null) {
            $text = $this->plainText($figure);

            if ($text !== '') {
                $this->appendParagraph($this->escape($text));
            }

            return;
        }

        $caption = $figure->querySelector('figcaption');

        $this->appendImage(
            $image,
            $caption === null ? '' : $this->plainText($caption),
            (string) ($figure->getAttribute('data-credit') ?? ''),
        );
    }

    private function appendImage(Element $image, string $caption, string $credit): void
    {
        $this->blocks[] = [
            'type' => 'image',
            'data' => [
                'file' => ['url' => trim((string) $image->getAttribute('src'))],
                'alt' => trim((string) $image->getAttribute('alt')),
                'caption' => $caption,
                'credit' => $credit !== '' ? $credit : (string) ($image->getAttribute('data-credit') ?? ''),
            ],
        ];
    }

    private function convertTable(Element $table): void
    {
        $rows = [];
        $withHeadings = false;

        foreach ($table->querySelectorAll('tr') as $rowIndex => $row) {
            $cells = [];
            $allHeadings = true;

            foreach ($this->childElements($row) as $cell) {
                $cellTag = strtolower($cell->localName);

                if (! in_array($cellTag, ['td', 'th'], true)) {
                    continue;
                }

                $allHeadings = $allHeadings && $cellTag === 'th';
                $cells[] = $this->inlineHtml($cell);
            }

            if ($cells === []) {
                continue;
            }

            if ($rows === []) {
                $inHead = $row->parentElement !== null && strtolower($row->parentElement->localName) === 'thead';
                $withHeadings = $inHead || $allHeadings;
            }

            $rows[] = $cells;
        }

        if ($rows === []) {
            return;
        }

        $this->blocks[] = [
            'type' => 'table',
            'data' => ['withHeadings' => $withHeadings, 'content' => $rows],
        ];
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private function restorePlaceholder(Element $element): array
    {
        $type = (string) $element->getAttribute('data-block-type');
        $data = json_decode((string) $element->getAttribute('data-block'), true);

        if (! in_array($type, EditorHtmlPresenter::PLACEHOLDER_TYPES, true) || ! is_array($data)) {
            throw ValidationException::withMessages([
                'content' => 'A read-only block was changed or damaged. Reload the editor and try again, or edit the block in Editor.js.',
            ]);
        }

        return ['type' => $type, 'data' => $data];
    }

    private function inlineHtml(Element $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            if ($child instanceof Element && in_array(strtolower($child->localName), ['p', 'div'], true)) {
                $html .= ($html === '' ? '' : '<br>').$child->innerHTML;

                continue;
            }

            $html .= $child instanceof Element ? $this->outerHtml($child) : $this->escape($child->textContent ?? '');
        }

        $sanitized = $this->inline->sanitize(trim($html));

        return $this->isBlank($sanitized) ? '' : $this->trimBreaks($sanitized);
    }

    /**
     * @return list<Element>
     */
    private function childElements(Element $element): array
    {
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function outerHtml(Element $element): string
    {
        return (string) $element->ownerDocument?->saveHtml($element);
    }

    private function plainText(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent ?? '') ?? '');
    }

    private function isBlank(string $html): bool
    {
        $text = str_replace(["\u{00A0}", '&nbsp;'], ' ', strip_tags($html, '<br>'));

        return trim(str_replace('<br>', '', $text)) === '';
    }

    private function trimBreaks(string $html): string
    {
        return (string) preg_replace('/^(?:\s*<br>)+|(?:<br>\s*)+$/', '', $html);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
