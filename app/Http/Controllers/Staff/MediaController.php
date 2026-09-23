<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Integrations\UnsplashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Lightweight helpers for Editor.js media (issues #5 / #75).
 */
class MediaController extends Controller
{
    public function byUrl(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        return response()->json([
            'success' => 1,
            'file' => [
                'url' => $validated['url'],
            ],
        ]);
    }

    public function linkMeta(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $host = parse_url($validated['url'], PHP_URL_HOST) ?: $validated['url'];

        return response()->json([
            'success' => 1,
            'meta' => [
                'title' => $host,
            ],
        ]);
    }

    /**
     * Trusted staff multipart upload for Editor.js image/file tools (#75).
     */
    public function upload(Request $request): JsonResponse
    {
        $kinds = config('newsroom.editor_uploads.kinds', []);
        $kind = (string) $request->input('kind', 'file');

        if (! is_array($kinds) || ! array_key_exists($kind, $kinds)) {
            throw ValidationException::withMessages([
                'kind' => 'Upload kind must be image or file.',
            ]);
        }

        /** @var array{mimes: list<string>, max_kb: int} $rules */
        $rules = $kinds[$kind];
        $mimes = implode(',', $rules['mimes']);
        $maxKb = (int) $rules['max_kb'];

        $validated = $request->validate([
            'kind' => ['nullable', Rule::in(array_keys($kinds))],
            'file' => ['required', 'file', 'mimes:'.$mimes, 'max:'.$maxKb],
        ]);

        $upload = $validated['file'];
        $extension = strtolower((string) ($upload->getClientOriginalExtension() ?: $upload->extension() ?: 'bin'));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';

        $relative = sprintf(
            '%s/%s/%s/%s.%s',
            trim((string) config('newsroom.editor_uploads.path_prefix', 'editor'), '/'),
            now()->format('Y'),
            now()->format('m'),
            (string) Str::uuid(),
            $extension,
        );

        $disk = (string) config('newsroom.editor_uploads.disk', 'public');
        Storage::disk($disk)->putFileAs(
            dirname($relative),
            $upload,
            basename($relative),
        );

        $publicUrl = url('storage/'.$relative);
        $originalName = pathinfo((string) $upload->getClientOriginalName(), PATHINFO_FILENAME);
        $title = Str::limit(trim(strip_tags($originalName)), 200, '');
        $sizeLabel = $this->humanSize((int) $upload->getSize());

        return response()->json([
            'success' => 1,
            'file' => [
                'url' => $publicUrl,
                'title' => $title !== '' ? $title : null,
                'size' => $sizeLabel,
            ],
        ]);
    }

    public function unsplashSearch(Request $request, UnsplashService $unsplash): JsonResponse
    {
        if (! $unsplash->enabled()) {
            return response()->json([
                'enabled' => false,
                'results' => [],
                'message' => 'Unsplash is not configured.',
            ]);
        }

        $validated = $request->validate([
            'q' => ['required', 'string', 'max:120'],
        ]);

        return response()->json([
            'enabled' => true,
            'results' => $unsplash->search($validated['q']),
        ]);
    }

    public function unsplashSelect(Request $request, UnsplashService $unsplash): JsonResponse
    {
        if (! $unsplash->enabled()) {
            return response()->json(['message' => 'Unsplash is not configured.'], 503);
        }

        $validated = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'url' => ['required', 'url', 'max:2048'],
            'credit' => ['required', 'string', 'max:255'],
            'alt' => ['nullable', 'string', 'max:255'],
        ]);

        $unsplash->trackDownload($validated['id']);

        return response()->json([
            'success' => 1,
            'file' => [
                'url' => $validated['url'],
                'credit' => $validated['credit'],
                'alt' => $validated['alt'] ?? '',
            ],
        ]);
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }
}
