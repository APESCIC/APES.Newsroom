<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\Analytics\ContentViewRecorder;
use App\Services\Publishing\PublicPagePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function __construct(
        private readonly PublicPagePresenter $presenter,
        private readonly ContentViewRecorder $views,
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
        ]);
    }
}
