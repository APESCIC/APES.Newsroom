<?php

namespace App\Http\Controllers\Staff;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StorePageRequest;
use App\Http\Requests\Staff\UpdatePageRequest;
use App\Models\Page;
use App\Models\Redirect;
use App\Services\EditorJs\BlockValidator;
use App\Services\Publishing\PublicPagePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function __construct(
        private readonly BlockValidator $validator,
    ) {}

    public function index(Request $request): Response
    {
        $query = Page::query()->with('author');

        if ($request->user()->role === Role::Staff) {
            $query->where('author_id', $request->user()->id);
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $pages = $query->latest('updated_at')->get()->map(fn (Page $page) => [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'status' => $page->status->value,
            'updated_at' => $page->updated_at?->toIso8601String(),
            'author' => $page->author->name,
        ]);

        return Inertia::render('Staff/Pages/Index', [
            'pages' => $pages,
            'filterStatus' => $status ?: null,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Staff/Pages/Edit', [
            'page' => null,
        ]);
    }

    public function store(StorePageRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $content = $this->validator->validate($validated['content']);
        unset($validated['expected_updated_at']);

        $page = Page::create([
            ...$validated,
            'content' => $content,
            'slug' => $validated['slug'] ?? Str::slug($validated['title']),
            'author_id' => $request->user()->id,
            'status' => PostStatus::Draft,
        ]);

        return redirect()->route('staff.pages.edit', $page);
    }

    public function edit(Request $request, Page $page): Response
    {
        $this->authorizeEdit($request, $page);

        return Inertia::render('Staff/Pages/Edit', [
            'page' => $this->editPayload($page),
        ]);
    }

    public function update(UpdatePageRequest $request, Page $page): RedirectResponse
    {
        $this->authorizeEdit($request, $page);

        $validated = $request->validated();
        $this->assertNoConflict($page, $validated['expected_updated_at'] ?? null);
        unset($validated['expected_updated_at']);

        $content = $this->validator->validate($validated['content']);
        $oldSlug = $page->slug;
        $wasPublished = $page->status === PostStatus::Published;

        $page->fill([
            ...$validated,
            'content' => $content,
        ]);
        $page->save();

        if ($wasPublished && $oldSlug !== $page->slug) {
            Redirect::query()->updateOrCreate(
                ['from_path' => '/pages/'.$oldSlug],
                ['to_path' => '/pages/'.$page->slug, 'status_code' => 301],
            );
        }

        return back();
    }

    public function publish(Request $request, Page $page): RedirectResponse
    {
        $this->authorizeEdit($request, $page);

        $page->status = PostStatus::Published;
        $page->published_at = $page->published_at ?? now();
        $page->save();

        return back();
    }

    public function unpublish(Request $request, Page $page): RedirectResponse
    {
        $this->authorizeEdit($request, $page);

        $page->status = PostStatus::Unpublished;
        $page->save();

        return back();
    }

    public function destroy(Request $request, Page $page): RedirectResponse
    {
        $this->authorizeEdit($request, $page);

        $page->status = PostStatus::Deleted;
        $page->save();
        $page->delete();

        return redirect()->route('staff.pages.index');
    }

    public function preview(Request $request, Page $page, PublicPagePresenter $presenter): Response
    {
        $this->authorizeEdit($request, $page);

        return Inertia::render('Pages/Show', [
            'page' => $presenter->payload($page),
            'preview' => true,
        ]);
    }

    private function authorizeEdit(Request $request, Page $page): void
    {
        abort_unless($page->isEditableBy($request->user()), 403);
    }

    private function assertNoConflict(Page $page, ?string $expectedUpdatedAt): void
    {
        if ($expectedUpdatedAt === null || $expectedUpdatedAt === '') {
            return;
        }

        $current = $page->updated_at?->toIso8601String();

        if ($current !== null && $current !== $expectedUpdatedAt) {
            throw ValidationException::withMessages([
                'conflict' => 'This page was updated elsewhere. Reload and try again.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function editPayload(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'excerpt' => $page->excerpt,
            'content' => $page->content,
            'status' => $page->status->value,
            'hero_image' => $page->hero_image,
            'hero_image_alt' => $page->hero_image_alt,
            'hero_image_caption' => $page->hero_image_caption,
            'hero_image_credit' => $page->hero_image_credit,
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'canonical_url' => $page->canonical_url,
            'published_at' => $page->published_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }
}
