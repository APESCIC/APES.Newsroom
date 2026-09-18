<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreReleaseRequest;
use App\Models\Release;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReleaseController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        $releases = Release::query()
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Release $release) => [
                'id' => $release->id,
                'version' => $release->version,
                'released_at' => $release->released_at?->toDateString(),
                'channel' => $release->channel->value,
                'is_current' => $release->is_current,
                'is_published' => $release->is_published,
                'slug' => $release->slug,
                'theme' => $release->theme,
            ]);

        return Inertia::render('Admin/Releases/Index', [
            'releases' => $releases,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeAdmin();

        return Inertia::render('Admin/Releases/Edit', [
            'release' => null,
            'changeTypes' => Release::CHANGE_TYPES,
            'topicTags' => Release::TOPIC_TAGS,
        ]);
    }

    public function store(StoreReleaseRequest $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $data = $this->payloadFromRequest($request);
        $release = Release::create($data);

        if ($release->is_current) {
            $release->markAsCurrent();
        }

        $this->audit->record($request->user(), 'release.created', $release, [
            'version' => $release->version,
            'published' => $release->is_published,
        ], $request);

        return redirect()
            ->route('admin.releases.index')
            ->with('status', 'Release '.$release->version.' created.');
    }

    public function edit(Request $request, Release $release): Response
    {
        $this->authorizeAdmin();

        return Inertia::render('Admin/Releases/Edit', [
            'release' => [
                'id' => $release->id,
                'version' => $release->version,
                'previous_version' => $release->previous_version,
                'released_at' => $release->released_at?->toDateString(),
                'channel' => $release->channel->value,
                'version_type' => $release->version_type,
                'theme' => $release->theme,
                'is_current' => $release->is_current,
                'is_published' => $release->is_published,
                'slug' => $release->slug,
                'change_types' => $release->change_types ?? [],
                'topic_tags' => $release->topic_tags ?? [],
                'summary' => $release->summary,
                'detailed_changes_text' => implode("\n", $release->detailed_changes ?? []),
                'affected_areas_text' => implode("\n", $release->affected_areas ?? []),
                'version_decision_text' => implode("\n", $release->version_decision ?? []),
                'validation_text' => implode("\n", $release->validation ?? []),
            ],
            'changeTypes' => Release::CHANGE_TYPES,
            'topicTags' => Release::TOPIC_TAGS,
        ]);
    }

    public function update(StoreReleaseRequest $request, Release $release): RedirectResponse
    {
        $this->authorizeAdmin();

        $data = $this->payloadFromRequest($request);
        $release->update($data);

        if ($release->is_current) {
            $release->markAsCurrent();
        }

        $this->audit->record($request->user(), 'release.updated', $release, [
            'version' => $release->version,
            'published' => $release->is_published,
        ], $request);

        return redirect()
            ->route('admin.releases.index')
            ->with('status', 'Release '.$release->version.' updated.');
    }

    public function destroy(Request $request, Release $release): RedirectResponse
    {
        $this->authorizeAdmin();

        $version = $release->version;
        $release->delete();

        $this->audit->record($request->user(), 'release.deleted', null, [
            'version' => $version,
        ], $request);

        return redirect()
            ->route('admin.releases.index')
            ->with('status', 'Release '.$version.' deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromRequest(StoreReleaseRequest $request): array
    {
        $validated = $request->validated();
        $version = (string) $validated['version'];
        $slug = trim((string) ($validated['slug'] ?? ''));
        if ($slug === '') {
            $slug = Release::slugFromVersion($version);
        }

        return [
            'version' => $version,
            'previous_version' => $validated['previous_version'] ?? null,
            'released_at' => $validated['released_at'],
            'channel' => $validated['channel'],
            'version_type' => $validated['version_type'] ?? null,
            'theme' => $validated['theme'] ?? null,
            'is_current' => (bool) ($validated['is_current'] ?? false),
            'is_published' => (bool) ($validated['is_published'] ?? false),
            'slug' => $slug,
            'change_types' => $validated['change_types'] ?? [],
            'topic_tags' => $validated['topic_tags'] ?? [],
            'summary' => $validated['summary'],
            'detailed_changes' => $validated['detailed_changes'] ?? [],
            'affected_areas' => $validated['affected_areas'] ?? [],
            'version_decision' => $validated['version_decision'] ?? [],
            'validation' => $validated['validation'] ?? [],
        ];
    }

    private function authorizeAdmin(): void
    {
        if (! request()->user()?->role->atLeast(Role::Admin)) {
            abort(403);
        }
    }
}
