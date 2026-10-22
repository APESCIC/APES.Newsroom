<?php

namespace App\Services\EditorJs;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Reduces an Editor.js text field to a safe inline HTML subset (issue #390).
 *
 * Allowed: strong, em, code, br and a[href] (http, https, mailto). Every other
 * element is unwrapped, dangerous containers are dropped with their content,
 * and every attribute except a validated href is removed.
 */
class InlineHtmlSanitizer
{
    /** @var array<string, string> */
    private const RENAMED_TAGS = [
        'strong' => 'strong',
        'b' => 'strong',
        'em' => 'em',
        'i' => 'em',
        'code' => 'code',
    ];

    /** @var list<string> */
    private const DROPPED_TAGS = [
        'script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed',
        'svg', 'math', 'textarea', 'select', 'option', 'title', 'head', 'canvas',
        'audio', 'video', 'img', 'picture', 'source', 'input', 'button', 'form',
    ];

    /** @var list<string> */
    private const LINK_SCHEMES = ['http', 'https', 'mailto'];

    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        if (! str_contains($html, '<') && ! str_contains($html, '&')) {
            return $this->escape($html);
        }

        $document = HTMLDocument::createEmpty();
        $container = $document->createElement('div');
        $container->innerHTML = $html;

        return $this->renderChildren($container, false);
    }

    private function renderChildren(Node $parent, bool $insideLink): string
    {
        $output = '';

        foreach ($parent->childNodes as $child) {
            $output .= $this->renderNode($child, $insideLink);
        }

        return $output;
    }

    private function renderNode(Node $node, bool $insideLink): string
    {
        if ($node instanceof Text) {
            return $this->escape($node->textContent ?? '');
        }

        if (! $node instanceof Element) {
            return '';
        }

        $tag = strtolower($node->localName);

        if (in_array($tag, self::DROPPED_TAGS, true)) {
            return '';
        }

        if ($tag === 'br') {
            return '<br>';
        }

        if (isset(self::RENAMED_TAGS[$tag])) {
            $inner = $this->renderChildren($node, $insideLink);

            return $inner === '' ? '' : '<'.self::RENAMED_TAGS[$tag].'>'.$inner.'</'.self::RENAMED_TAGS[$tag].'>';
        }

        if ($tag === 'a' && ! $insideLink) {
            $href = $this->safeHref((string) $node->getAttribute('href'));
            $inner = $this->renderChildren($node, true);

            if ($href === null || $inner === '') {
                return $inner;
            }

            return '<a href="'.$this->escapeAttribute($href).'" rel="noopener noreferrer">'.$inner.'</a>';
        }

        return $this->renderChildren($node, $insideLink);
    }

    private function safeHref(string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || preg_match('/[\x00-\x1F\x7F]/', $href)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        if (! in_array($scheme, self::LINK_SCHEMES, true)) {
            return null;
        }

        if ($scheme !== 'mailto' && ! filter_var($href, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $href;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
