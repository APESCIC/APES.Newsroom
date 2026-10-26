<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Page;
use App\Models\Post;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaUsageFinder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff media library for reusing and safely deleting uploaded images (#537).
 */
class MediaLibraryController extends Controller
{
    private const PER_PAGE = 24;

    public function __construct(
        private readonly MediaUsageFinder $usage,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MediaAsset::class);
        $search = trim($request->string('q')->toString());

        return Inertia::render('Staff/Media/Index', [
            'assets' => $this->paginate($search, withUsage: true),
            'search' => $search,
        ]);
    }

    public function picker(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MediaAsset::class);

        return response()->json($this->paginate(trim($request->string('q')->toString()), withUsage: false));
    }

    public function usage(MediaAsset $asset): JsonResponse
    {
        Gate::authorize('view', $asset);

        return response()->json(['usage' => $this->usage->find($asset)]);
    }

    public function destroy(Request $request, MediaAsset $asset): RedirectResponse
    {
        Gate::authorize('delete', $asset);

        $usage = $this->usage->find($asset);
        if ($usage !== [] && ! $request->boolean('confirm')) {
            throw ValidationException::withMessages([
                'confirm' => 'This image is used by: '.collect($usage)->pluck('title')->join(', ').'. Confirm to delete it anyway.',
            ]);
        }

        DB::transaction(function () use ($asset) {
            foreach ([Post::class, Page::class] as $model) {
                $model::query()->where('hero_media_id', $asset->id)->update([
                    'hero_image' => null,
                    'hero_media_id' => null,
                    'hero_focal_x' => null,
                    'hero_focal_y' => null,
                ]);
            }

            $asset->delete();
        });

        Storage::disk($asset->disk)->deleteDirectory($asset->directory());

        $this->audit->record($request->user(), 'media.deleted', $asset, [
            'original_name' => $asset->original_name,
            'path' => $asset->path,
            'used_by' => collect($usage)->map(fn (array $item) => [
                'type' => $item['type'],
                'id' => $item['id'],
                'title' => $item['title'],
            ])->all(),
        ], $request);

        return redirect()->route('staff.media.index')->with('status', 'Image deleted.');
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(string $search, bool $withUsage): LengthAwarePaginator
    {
        $paginator = MediaAsset::query()
            ->with('uploader:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('original_name', 'like', '%'.$search.'%')
                ->orWhere('alt', 'like', '%'.$search.'%')))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $counts = $withUsage ? $this->usage->counts($paginator->items()) : [];

        return $paginator->through(fn (MediaAsset $asset) => [
            ...$asset->editorPayload(),
            'uploaded_by' => $asset->uploader?->name,
            'created_at' => $asset->created_at?->toIso8601String(),
            'usage_count' => $counts[$asset->id] ?? null,
        ]);
    }
}
