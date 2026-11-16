<?php

namespace App\Http\Controllers;

use App\Models\Release;
use App\Services\Seo\PageMetaFactory;
use Inertia\Inertia;
use Inertia\Response;

class ChangeLogHubController extends Controller
{
    public function __construct(
        private readonly PageMetaFactory $meta,
    ) {}

    public function __invoke(): Response
    {
        $releases = Release::query()
            ->published()
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Release $release) => $release->toPublicArray())
            ->values();

        $current = $releases->firstWhere('is_current', true) ?? $releases->first();

        return Inertia::render('ChangeLogHub/Index', [
            'releases' => $releases,
            'current' => $current,
            'canonicalUrl' => url('/change-log-hub'),
            'filters' => [
                ['value' => 'all', 'label' => 'All releases'],
                ['value' => 'current', 'label' => 'Current release'],
                ['value' => 'beta', 'label' => 'Beta'],
                ['value' => 'added', 'label' => 'Added'],
                ['value' => 'changed', 'label' => 'Changed'],
                ['value' => 'fixed', 'label' => 'Fixed'],
                ['value' => 'removed', 'label' => 'Removed'],
                ['value' => 'security', 'label' => 'Security'],
                ['value' => 'compliance', 'label' => 'Compliance'],
                ['value' => 'accessibility', 'label' => 'Accessibility'],
                ['value' => 'public-facing', 'label' => 'Public-facing'],
                ['value' => 'internal-only', 'label' => 'Internal-only'],
            ],
        ])->withViewData(['meta' => $this->meta->forChangeLogHub()]);
    }
}
