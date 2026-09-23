<?php

namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use App\Services\Mailing\ConsentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewsletterSignupController extends Controller
{
    public function __construct(private readonly ConsentService $consent) {}

    public function show(Request $request, string $slug): Response
    {
        $newsletter = $this->activeNewsletter($slug);

        return Inertia::render('Mailing/NewsletterSignup', [
            'newsletter' => [
                'name' => $newsletter->name,
                'slug' => $newsletter->slug,
                'description' => $newsletter->description,
            ],
            'embed' => $request->boolean('embed'),
        ]);
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        $newsletter = $this->activeNewsletter($slug);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $this->consent->subscribeToNewsletter(
            email: $validated['email'],
            newsletter: $newsletter,
            source: 'newsletter_signup',
            user: $request->user(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return back()->with('status', 'check-email');
    }

    private function activeNewsletter(string $slug): Newsletter
    {
        $newsletter = Newsletter::query()->where('slug', $slug)->firstOrFail();
        abort_if($newsletter->isArchived(), 404);

        return $newsletter;
    }
}
