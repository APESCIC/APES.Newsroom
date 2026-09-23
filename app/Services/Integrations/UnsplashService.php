<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class UnsplashService
{
    public function enabled(): bool
    {
        return filled(config('newsroom.unsplash.access_key'));
    }

    /**
     * @return list<array{id: string, thumb: string, full: string, alt: string, credit: string, photographer: string, photographer_url: string}>
     */
    public function search(string $query, int $perPage = 12): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Client-ID '.config('newsroom.unsplash.access_key'),
                    'Accept-Version' => 'v1',
                ])
                ->get('https://api.unsplash.com/search/photos', [
                    'query' => $query,
                    'per_page' => min(max($perPage, 1), 30),
                ]);

            if (! $response->successful()) {
                Log::warning('Unsplash search failed', ['status' => $response->status()]);

                return [];
            }

            $results = $response->json('results') ?? [];
            $out = [];

            foreach ($results as $photo) {
                if (! is_array($photo)) {
                    continue;
                }
                $user = is_array($photo['user'] ?? null) ? $photo['user'] : [];
                $urls = is_array($photo['urls'] ?? null) ? $photo['urls'] : [];
                $name = (string) ($user['name'] ?? 'Unsplash photographer');
                $username = (string) ($user['username'] ?? '');
                $profile = $username !== ''
                    ? 'https://unsplash.com/@'.$username.'?utm_source=apes_newsroom&utm_medium=referral'
                    : 'https://unsplash.com/?utm_source=apes_newsroom&utm_medium=referral';

                $out[] = [
                    'id' => (string) ($photo['id'] ?? ''),
                    'thumb' => (string) ($urls['thumb'] ?? $urls['small'] ?? ''),
                    'full' => (string) ($urls['regular'] ?? $urls['full'] ?? ''),
                    'alt' => (string) ($photo['alt_description'] ?? $photo['description'] ?? ''),
                    'photographer' => $name,
                    'photographer_url' => $profile,
                    'credit' => 'Photo by '.$name.' on Unsplash',
                ];
            }

            return $out;
        } catch (Throwable $e) {
            Log::warning('Unsplash search exception', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function trackDownload(string $photoId): void
    {
        if (! $this->enabled() || $photoId === '') {
            return;
        }

        try {
            Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Client-ID '.config('newsroom.unsplash.access_key'),
                    'Accept-Version' => 'v1',
                ])
                ->get('https://api.unsplash.com/photos/'.$photoId.'/download');
        } catch (Throwable $e) {
            Log::debug('Unsplash download ping failed', ['error' => $e->getMessage()]);
        }
    }
}
