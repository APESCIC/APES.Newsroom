<?php

namespace App\Services\Media;

/**
 * Recognises image URLs left behind by the Ghost import (#538).
 *
 * Ghost exports reference images as `__GHOST_URL__/content/images/...`, and
 * the first imports also produced `/content/images/...` paths on the old and
 * new hostnames. The Newsroom never serves `/content/images/`, so all of
 * these are broken until the files are copied into Newsroom storage.
 */
class GhostImageUrls
{
    private const PATH = '/content/images/[\w.~%+\-]+(?:/[\w.~%+\-]+)*';

    /** @var list<string> */
    private const GHOST_HOSTS = [
        'https?://(?:www\.)?apesnews\.org\.uk',
        'https?://ghost-legacy\.apesnews\.org\.uk',
    ];

    public function pattern(): string
    {
        return '#'.$this->expression().'#';
    }

    public function isGhostImage(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        return preg_match('#^(?:'.$this->expression().')$#', trim($url)) === 1;
    }

    private function expression(): string
    {
        $prefixes = ['__GHOST_URL__', ...self::GHOST_HOSTS];
        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '') {
            $prefixes[] = preg_quote($appUrl, '#');
        }

        return '(?:'.implode('|', $prefixes).'|(?<![\w.:/\-]))'.self::PATH;
    }

    /**
     * @return list<string>
     */
    public function find(string $text): array
    {
        if (! str_contains($text, '/content/images/')) {
            return [];
        }

        preg_match_all($this->pattern(), $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * `__GHOST_URL__/content/images/2024/01/a.jpg` => `/content/images/2024/01/a.jpg`
     */
    public function relativePath(string $url): string
    {
        $position = strpos($url, '/content/images/');

        return $position === false ? $url : substr($url, $position);
    }

    /**
     * Ghost serves resized copies under `/content/images/size/w1000/...`;
     * the original lives at the same path without the size segment.
     */
    public function originalPath(string $relative): string
    {
        return (string) preg_replace('#^/content/images/size/w\d+(?:h\d+)?/#', '/content/images/', $relative);
    }
}
