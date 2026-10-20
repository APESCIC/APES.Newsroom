<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Mailing\CampaignService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function preview(Request $request, Post $post): Response
    {
        $this->authorizeAdmin();

        return Inertia::render('Staff/Campaigns/Preview', [
            'post' => [
                'id' => $post->id,
                'title' => $post->title,
                'status' => $post->status->value,
                'email_on_publish' => $post->email_on_publish,
                'mailing_lists' => $post->mailing_lists ?? [],
            ],
            'snapshot' => $this->campaigns->previewPayload($post),
        ]);
    }

    public function testSend(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $this->campaigns->createTestSend($post, $request->user(), $validated['email']);

        return back()->with('status', 'test-send-queued');
    }

    private function authorizeAdmin(): void
    {
        Gate::authorize('publish', Post::class);
    }
}
