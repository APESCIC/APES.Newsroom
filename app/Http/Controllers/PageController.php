<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\Analytics\ContentViewRecorder;
use App\Services\Publishing\PublicPagePresenter;
use App\Services\Seo\PageMetaFactory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function __construct(
        private readonly PublicPagePresenter $presenter,
        private readonly ContentViewRecorder $views,
        private readonly PageMetaFactory $meta,
    ) {}

    public function show(Request $request, string $slug): Response
    {
        $page = Page::published()
            ->where('slug', $slug)
            ->with('author')
            ->firstOrFail();

        $this->views->record($request, page: $page);

        return Inertia::render('Pages/Show', [
            'page' => $this->presenter->payload($page, $request->user()),
        ])->withViewData(['meta' => $this->meta->forPage($page)]);
    }
}
