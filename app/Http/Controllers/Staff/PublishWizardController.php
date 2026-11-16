<?php

namespace App\Http\Controllers\Staff;

use App\Enums\ContentVisibility;
use App\Enums\MailingList;
use App\Enums\PublishChannel;
use App\Http\Controllers\Controller;
use App\Models\NewsletterSegment;
use App\Models\Post;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\CampaignService;
use App\Services\Publishing\PublishTargetService;
use App\Services\Publishing\PublishWizardService;
use App\Services\Seo\PageMetaFactory;
use App\Support\EmailTemplates\NewsletterTemplateChoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PublishWizardController extends Controller
{
    public function __construct(
        private readonly PublishWizardService $wizard,
        private readonly PublishTargetService $targets,
        private readonly CampaignService $campaigns,
        private readonly CampaignSender $sender,
        private readonly PageMetaFactory $pageMeta,
    ) {}

    public function show(Request $request, Post $post, string $step = 'audience'): Response|RedirectResponse
    {
        $this->authorizePublish();
        $step = $this->normalizeStep($step);

        if ($step === 'social') {
            return redirect()->route('staff.posts.publish.show', ['post' => $post, 'step' => 'confirm']);
        }

        $this->targets->ensureTargets($post);
        $draft = $this->wizard->draftState($post->fresh());

        if ($step === 'result' && ! $this->hasReachedResult($post)) {
            return redirect()->route('staff.posts.publish.show', ['post' => $post, 'step' => 'confirm']);
        }

        return Inertia::render('Staff/Posts/Publish/Wizard', [
            'post' => $this->postPayload($post->fresh()),
            'step' => $step,
            'steps' => [
                ['id' => 'audience', 'label' => 'Audience', 'number' => 1],
                ['id' => 'when', 'label' => 'When', 'number' => 2],
                ['id' => 'social', 'label' => 'Social', 'number' => 3, 'disabled' => true, 'note' => 'Coming in v1.34.0'],
                ['id' => 'confirm', 'label' => 'Confirm', 'number' => 4],
                ['id' => 'result', 'label' => 'Result', 'number' => 5],
            ],
            'draft' => $draft,
            'targets' => $this->targetPayload($post),
            'recipientCounts' => $this->campaigns->recipientCountsForPost($post),
            'visibilityOptions' => collect(ContentVisibility::cases())->map(fn (ContentVisibility $v) => [
                'value' => $v->value,
                'label' => $v->label(),
            ])->values(),
            'mailingListOptions' => collect(MailingList::cases())->map(fn (MailingList $list) => [
                'value' => $list->value,
                'label' => $list->label(),
            ])->values(),
            'segmentOptions' => $this->segmentOptions(),
            'templateOptions' => NewsletterTemplateChoice::options(),
            'sender' => $this->senderOption($post),
            'shareUrl' => $this->canonicalShareUrl($post),
            'timezone' => 'Europe/London',
        ]);
    }

    public function saveAudience(Request $request, Post $post): RedirectResponse
    {
        $this->authorizePublish();

        $validated = $request->validate([
            'visibility' => ['required', Rule::enum(ContentVisibility::class)],
            'mailing_lists' => ['nullable', 'array'],
            'mailing_lists.*' => ['string', Rule::enum(MailingList::class)],
            'newsletter_segment_id' => ['nullable', 'integer', 'exists:newsletter_segments,id'],
            'newsletter_template' => ['nullable', 'string'],
            'email_mode' => ['required', Rule::in(['now', 'schedule', 'dont'])],
        ]);

        $this->wizard->saveAudience($post, $validated);

        return redirect()->route('staff.posts.publish.show', [
            'post' => $post,
            'step' => $request->input('next', 'when'),
        ]);
    }

    public function saveWhen(Request $request, Post $post): RedirectResponse
    {
        $this->authorizePublish();

        $validated = $request->validate([
            'website_mode' => ['required', Rule::in(['now', 'schedule'])],
            'email_mode' => ['required', Rule::in(['now', 'schedule', 'dont'])],
            'website_at' => ['nullable', 'date'],
            'email_at' => ['nullable', 'date'],
        ]);

        $this->wizard->saveWhen($post, $validated);

        return redirect()->route('staff.posts.publish.show', [
            'post' => $post,
            'step' => $request->input('next', 'confirm'),
        ]);
    }

    public function confirm(Request $request, Post $post): RedirectResponse
    {
        $this->authorizePublish();

        // Double-submit guard: lock handled inside wizard; second click redirects to result.
        $result = $this->wizard->confirm($post, $request->user(), $request);

        return redirect()->route('staff.posts.publish.show', [
            'post' => $result['post'],
            'step' => 'result',
        ]);
    }

    public function retry(Request $request, Post $post, string $channel): RedirectResponse
    {
        $this->authorizePublish();
        $publishChannel = PublishChannel::from($channel);
        $this->wizard->retry($post, $publishChannel, $request->user(), $request);

        return redirect()->route('staff.posts.publish.show', [
            'post' => $post,
            'step' => 'result',
        ]);
    }

    public function cancel(Request $request, Post $post, string $channel): RedirectResponse
    {
        $this->authorizePublish();
        $publishChannel = PublishChannel::from($channel);
        $this->wizard->cancel($post, $publishChannel, $request->user(), $request);

        return redirect()->route('staff.posts.publish.show', [
            'post' => $post,
            'step' => 'result',
        ]);
    }

    private function authorizePublish(): void
    {
        Gate::authorize('publish', Post::class);
    }

    private function normalizeStep(string $step): string
    {
        $step = strtolower($step);

        return in_array($step, PublishWizardService::STEPS, true) ? $step : 'audience';
    }

    private function hasReachedResult(Post $post): bool
    {
        $email = $this->targets->emailTarget($post);
        $meta = is_array($email->meta) ? $email->meta : [];

        return ($meta['wizard_step'] ?? null) === 'result'
            || $post->status->isPubliclyVisible()
            || $post->status->value === 'scheduled';
    }

    /**
     * @return array<string, mixed>
     */
    private function postPayload(Post $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'status' => $post->status->value,
            'visibility' => $post->visibility?->value ?? ContentVisibility::Public->value,
            'email_on_publish' => (bool) $post->email_on_publish,
            'mailing_lists' => $post->mailing_lists ?? [],
            'newsletter_segment_id' => $post->newsletter_segment_id,
            'newsletter_template' => $post->newsletter_template,
            'scheduled_for' => $post->scheduled_for?->timezone('Europe/London')->toIso8601String(),
            'published_at' => $post->published_at?->timezone('Europe/London')->toIso8601String(),
            'edit_url' => route('staff.posts.edit', $post),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function targetPayload(Post $post): array
    {
        return $this->targets->targetsFor($post)->values()->map(function ($target) {
            return [
                'id' => $target->id,
                'channel' => $target->channel->value,
                'channel_label' => $target->channel->label(),
                'status' => $target->status->value,
                'status_label' => $target->status->label(),
                'scheduled_for' => $target->scheduled_for?->timezone('Europe/London')->toIso8601String(),
                'started_at' => $target->started_at?->timezone('Europe/London')->toIso8601String(),
                'completed_at' => $target->completed_at?->timezone('Europe/London')->toIso8601String(),
                'result_message' => $target->result_message,
                'campaign_id' => $target->campaign_id,
                'meta' => $target->meta ?? [],
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function segmentOptions(): array
    {
        return NewsletterSegment::query()
            ->with('newsletter')
            ->orderBy('name')
            ->get()
            ->map(fn (NewsletterSegment $segment) => [
                'id' => $segment->id,
                'name' => $segment->name,
                'newsletter' => $segment->newsletter?->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function senderOption(Post $post): array
    {
        $newsletter = $this->sender->newsletterForPost($post);

        return [
            'verified' => $this->sender->isVerified($newsletter),
            'message' => $newsletter
                ? ($this->sender->isVerified($newsletter) ? null : $this->sender->blockedMessage($newsletter))
                : null,
            'identity' => $newsletter ? $this->sender->identity($newsletter) : null,
        ];
    }

    private function canonicalShareUrl(Post $post): string
    {
        $meta = $this->pageMeta->forArticle($post);

        return $meta['url'] ?? route('articles.show', $post->slug, absolute: true);
    }
}
