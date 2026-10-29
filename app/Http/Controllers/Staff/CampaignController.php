<?php

namespace App\Http\Controllers\Staff;

use App\Enums\CampaignStatus;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Post;
use App\Services\Mail\MailTransports;
use App\Services\Mailing\CampaignService;
use App\Support\Settings\SettingDefinitions;
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
            'liveCampaign' => $this->campaigns->liveSummary($post),
            'newsletterTransport' => SettingDefinitions::transportOptions()[app(MailTransports::class)->effectiveNewsletterTransport()] ?? null,
            'canResume' => $request->user()->can('access-admin-area'),
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

    /**
     * Restart a live send that paused because its transport failed.
     */
    public function resume(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('access-admin-area');

        $campaign = Campaign::query()
            ->where('post_id', $post->id)
            ->where('is_test', false)
            ->where('status', CampaignStatus::Paused->value)
            ->latest('id')
            ->first();

        if ($campaign === null) {
            return back()->withErrors(['resume' => 'There is no paused send for this post.']);
        }

        $remaining = $this->campaigns->resume($campaign, $request->user(), $request);

        return back()->with('status', "Resumed sending to {$remaining} remaining recipients.");
    }

    private function authorizeAdmin(): void
    {
        Gate::authorize('publish', Post::class);
    }
}
