<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class NewsletterController extends Controller
{
    public function index(): Response
    {
        $newsletters = Newsletter::query()
            ->withCount('subscriptions')
            ->orderBy('name')
            ->get()
            ->map(fn (Newsletter $newsletter) => [
                'id' => $newsletter->id,
                'name' => $newsletter->name,
                'slug' => $newsletter->slug,
                'description' => $newsletter->description,
                'legacy_list' => $newsletter->legacy_list?->value,
                'archived_at' => $newsletter->archived_at?->toIso8601String(),
                'subscriptions_count' => $newsletter->subscriptions_count,
            ]);

        return Inertia::render('Staff/Newsletters/Index', [
            'newsletters' => $newsletters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Staff/Newsletters/Edit', [
            'newsletter' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $newsletter = Newsletter::query()->create([
            'name' => $validated['name'],
            'slug' => $validated['slug'] !== '' ? $validated['slug'] : Str::slug($validated['name']),
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
        ]);

        return redirect()->route('staff.newsletters.edit', $newsletter);
    }

    public function edit(Newsletter $newsletter): Response
    {
        return Inertia::render('Staff/Newsletters/Edit', [
            'newsletter' => [
                'id' => $newsletter->id,
                'name' => $newsletter->name,
                'slug' => $newsletter->slug,
                'description' => $newsletter->description,
                'legacy_list' => $newsletter->legacy_list?->value,
                'archived_at' => $newsletter->archived_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, Newsletter $newsletter): RedirectResponse
    {
        $validated = $this->validated($request, $newsletter);

        $newsletter->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'] !== '' ? $validated['slug'] : $newsletter->slug,
            'description' => $validated['description'] !== '' ? $validated['description'] : null,
        ]);

        return back();
    }

    public function archive(Newsletter $newsletter): RedirectResponse
    {
        $newsletter->update(['archived_at' => now()]);

        return back();
    }

    public function restore(Newsletter $newsletter): RedirectResponse
    {
        $newsletter->update(['archived_at' => null]);

        return back();
    }

    /**
     * @return array{name: string, slug: string, description: string}
     */
    private function validated(Request $request, ?Newsletter $newsletter = null): array
    {
        $slugRule = 'unique:newsletters,slug';
        if ($newsletter) {
            $slugRule .= ','.$newsletter->id;
        }

        /** @var array{name: string, slug: string|null, description: string|null} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120', $slugRule],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return [
            'name' => $validated['name'],
            'slug' => (string) ($validated['slug'] ?? ''),
            'description' => (string) ($validated['description'] ?? ''),
        ];
    }
}
