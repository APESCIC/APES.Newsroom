<?php

namespace App\Http\Controllers\Staff;

use App\Enums\Channel;
use App\Enums\MailingList;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StorePostRequest;
use App\Http\Requests\Staff\UpdatePostRequest;
use App\Models\NewsletterSegment;
use App\Models\Post;
use App\Models\PostRevision;
use App\Models\Redirect;
use App\Models\Tag;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\EditorJs\BlockValidator;
use App\Services\Integrations\SlackEditorialNotifier;
use App\Services\Mailing\CampaignService;
use App\Services\Publishing\PublicArticlePresenter;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function __construct(
        private readonly BlockValidator $validator,
        private readonly CampaignService $campaigns,
        private readonly AuditLogger $audit,
        private readonly OutboundWebhookDispatcher $webhooks,
        private readonly SlackEditorialNotifier $slack,
    ) {}

    public function index(Request $request): Response
    {
        $query = Post::query()->with('author');

        if ($request->user()->role === Role::Staff) {
            $query->where('author_id', $request->user()->id);
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $posts = $query->latest('updated_at')->get()->map(fn (Post $post) => [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'status' => $post->status->value,
            'channel' => $post->channel->value,
            'updated_at' => $post->updated_at?->toIso8601String(),
            'author' => $post->author->name,
        ]);

        return Inertia::render('Staff/Posts/Index', [
            'posts' => $posts,
            'filterStatus' => $status ?: null,
            'canReview' => $request->user()->role->atLeast(Role::Admin),
        ]);
    }

    public function reviewQueue(Request $request): Response
    {
        abort_unless($request->user()->role->atLeast(Role::Admin), 403);

        $posts = Post::query()
            ->with('author')
            ->where('status', PostStatus::InReview)
            ->latest('updated_at')
            ->get()
            ->map(fn (Post $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'status' => $post->status->value,
                'channel' => $post->channel->value,
                'updated_at' => $post->updated_at?->toIso8601String(),
                'author' => $post->author->name,
                'review_notes' => $post->review_notes,
            ]);

        return Inertia::render('Staff/Posts/Review', ['posts' => $posts]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Staff/Posts/Edit', [
            'post' => null,
            'channels' => $this->channelOptions(),
            'mailingLists' => $this->mailingListOptions(),
            'segments' => $this->segmentOptions(),
            'staffUsers' => User::query()
                ->whereIn('role', [Role::Staff, Role::Admin, Role::SuperAdmin])
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]),
            'canPublish' => $request->user()->role->atLeast(Role::Admin),
            'revisions' => [],
        ]);
    }

    public function store(StorePostRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $content = $this->validator->validate($validated['content']);
        $tags = $validated['tags'] ?? [];
        $coAuthorIds = $validated['co_author_ids'] ?? [];
        unset($validated['tags'], $validated['co_author_ids'], $validated['expected_updated_at']);

        $post = Post::create([
            ...$validated,
            'content' => $content,
            'slug' => $validated['slug'] ?? Str::slug($validated['title']),
            'author_id' => $request->user()->id,
            'status' => PostStatus::Draft,
            'featured' => (bool) ($validated['featured'] ?? false),
        ]);

        $this->syncTags($post, $tags);
        $this->syncAuthors($post, $coAuthorIds);
        $this->saveRevision($post, $request->user()->id);

        return redirect()->route('staff.posts.edit', $post);
    }

    public function edit(Request $request, Post $post): Response
    {
        $this->authorizeEdit($post);
        $post->load('tags', 'authors', 'revisions.editor');

        return Inertia::render('Staff/Posts/Edit', [
            'post' => $this->editPayload($post),
            'channels' => $this->channelOptions(),
            'mailingLists' => $this->mailingListOptions(),
            'segments' => $this->segmentOptions(),
            'staffUsers' => User::query()
                ->whereIn('role', [Role::Staff, Role::Admin, Role::SuperAdmin])
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]),
            'canPublish' => $request->user()->role->atLeast(Role::Admin),
            'revisions' => $post->revisions()->latest()->limit(20)->get()->map(fn (PostRevision $revision) => [
                'id' => $revision->id,
                'title' => $revision->title,
                'editor' => $revision->editor?->name,
                'created_at' => $revision->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function update(UpdatePostRequest $request, Post $post): RedirectResponse
    {
        $this->authorizeEdit($post);

        $validated = $request->validated();

        if (isset($validated['expected_updated_at'])) {
            $expected = $validated['expected_updated_at'];
            unset($validated['expected_updated_at']);

            if ($post->updated_at?->toIso8601String() !== $expected) {
                return back()->withErrors([
                    'conflict' => 'This post was edited elsewhere. Reload to see the latest version, then retry.',
                ]);
            }
        }

        $content = $this->validator->validate($validated['content']);
        $tags = $validated['tags'] ?? [];
        $coAuthorIds = $validated['co_author_ids'] ?? [];
        unset($validated['tags'], $validated['co_author_ids']);

        $oldSlug = $post->slug;
        $wasPublished = $post->status === PostStatus::Published;

        $post->update([
            ...$validated,
            'content' => $content,
            'featured' => (bool) ($validated['featured'] ?? $post->featured),
        ]);

        $this->syncTags($post, $tags);
        $this->syncAuthors($post, $coAuthorIds);
        $this->saveRevision($post, $request->user()->id);

        if ($wasPublished && $oldSlug !== $post->slug) {
            Redirect::query()->updateOrCreate(
                ['from_path' => '/articles/'.$oldSlug],
                ['to_path' => '/articles/'.$post->slug, 'status_code' => 301],
            );
        }

        return back();
    }

    public function submitForReview(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeEdit($post);

        $post->update([
            'status' => PostStatus::InReview,
            'review_notes' => null,
        ]);

        $this->slack->notifyPostSubmittedForReview($post->fresh() ?? $post);

        return back();
    }

    public function reject(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        $post->update([
            'status' => PostStatus::Draft,
            'review_notes' => $validated['review_notes'],
        ]);

        return back();
    }

    public function schedule(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeAdmin();

        $request->validate(['scheduled_for' => ['required', 'date', 'after:now']]);

        $post->update([
            'status' => PostStatus::Scheduled,
            'scheduled_for' => $request->input('scheduled_for'),
        ]);

        return back();
    }

    public function publish(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeAdmin();

        $didPublish = false;
        $publishedPost = DB::transaction(function () use ($request, $post, &$didPublish) {
            $lockedPost = Post::query()->lockForUpdate()->findOrFail($post->id);

            if ($lockedPost->status === PostStatus::Published) {
                return $lockedPost;
            }

            if ($lockedPost->status === PostStatus::Deleted) {
                throw ValidationException::withMessages([
                    'status' => 'Deleted posts cannot be published.',
                ]);
            }

            $lockedPost->update([
                'status' => PostStatus::Published,
                'published_at' => now(),
                'scheduled_for' => null,
                'review_notes' => null,
            ]);

            $this->campaigns->createFromPublishedPost($lockedPost->fresh(), $request->user());
            $didPublish = true;

            return $lockedPost->fresh();
        });

        if ($didPublish) {
            $this->audit->record($request->user(), 'post.published', $publishedPost, [], $request);
            $this->webhooks->dispatch(OutboundWebhookDispatcher::EVENT_POST_PUBLISHED, [
                'post_id' => $publishedPost->id,
                'slug' => $publishedPost->slug,
                'title' => $publishedPost->title,
                'status' => $publishedPost->status->value,
                'published_at' => $publishedPost->published_at?->toIso8601String(),
            ]);
            $this->slack->notifyPostPublished($publishedPost);
        }

        return back();
    }

    public function unpublish(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeAdmin();

        $post->update([
            'status' => PostStatus::Unpublished,
            'scheduled_for' => null,
        ]);

        $this->audit->record($request->user(), 'post.unpublished', $post, [], $request);

        return back();
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeEdit($post);

        if ($request->user()->role === Role::Staff && $post->author_id !== $request->user()->id) {
            abort(403);
        }

        $post->update(['status' => PostStatus::Deleted]);
        $post->delete();

        return redirect()->route('staff.posts.index');
    }

    public function restoreRevision(Request $request, Post $post, PostRevision $revision): RedirectResponse
    {
        $this->authorizeEdit($post);
        abort_unless($revision->post_id === $post->id, 404);

        $post->update([
            'title' => $revision->title,
            'content' => $revision->content,
        ]);

        $this->saveRevision($post, $request->user()->id);

        return back();
    }

    public function preview(Request $request, Post $post, PublicArticlePresenter $articles): Response
    {
        $this->authorizeEdit($post);

        return Inertia::render('Articles/Show', [
            'article' => $articles->payload($post),
            'preview' => true,
            'comments' => [],
            'reactions' => [
                'helpful' => 0,
                'support' => 0,
                'thank_you' => 0,
                'mine' => [],
            ],
            'canEngage' => false,
        ]);
    }

    private function authorizeEdit(Post $post): void
    {
        if (! $post->isEditableBy(request()->user())) {
            abort(403);
        }
    }

    private function authorizeAdmin(): void
    {
        if (! request()->user()->role->atLeast(Role::Admin)) {
            abort(403);
        }
    }

    private function saveRevision(Post $post, int $editorId): void
    {
        PostRevision::create([
            'post_id' => $post->id,
            'editor_id' => $editorId,
            'content' => $post->content,
            'title' => $post->title,
        ]);
    }

    /**
     * @param  array<int, string>  $tagNames
     */
    private function syncTags(Post $post, array $tagNames): void
    {
        $ids = [];

        foreach ($tagNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $attrs = Tag::attributesFromName($name);
            $tag = Tag::query()->firstOrCreate(
                ['slug' => $attrs['slug']],
                ['name' => $attrs['name'], 'is_internal' => $attrs['is_internal']],
            );

            if ($tag->is_internal !== $attrs['is_internal'] || $tag->name !== $attrs['name']) {
                $tag->fill([
                    'name' => $attrs['name'],
                    'is_internal' => $attrs['is_internal'],
                ])->save();
            }

            $ids[] = $tag->id;
        }

        $post->tags()->sync($ids);
    }

    /**
     * @param  array<int, int|string>  $coAuthorIds
     */
    private function syncAuthors(Post $post, array $coAuthorIds): void
    {
        $ids = collect($coAuthorIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->push((int) $post->author_id)
            ->unique()
            ->values()
            ->all();

        $post->authors()->sync($ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function editPayload(Post $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'content' => $post->content,
            'status' => $post->status->value,
            'visibility' => $post->visibility?->value ?? 'public',
            'channel' => $post->channel->value,
            'hero_image' => $post->hero_image,
            'hero_image_alt' => $post->hero_image_alt,
            'hero_image_caption' => $post->hero_image_caption,
            'hero_image_credit' => $post->hero_image_credit,
            'meta_title' => $post->meta_title,
            'meta_description' => $post->meta_description,
            'canonical_url' => $post->canonical_url,
            'scheduled_for' => $post->scheduled_for?->format('Y-m-d\TH:i'),
            'email_on_publish' => $post->email_on_publish,
            'mailing_lists' => $post->mailing_lists ?? [],
            'newsletter_segment_id' => $post->newsletter_segment_id,
            'review_notes' => $post->review_notes,
            'tags' => $post->tags->pluck('name')->all(),
            'featured' => (bool) $post->featured,
            'co_author_ids' => $post->authors
                ->pluck('id')
                ->reject(fn ($id) => (int) $id === (int) $post->author_id)
                ->values()
                ->all(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    /**
     * @return list<array{id: int, name: string, newsletter: string, also: string}>
     */
    private function segmentOptions(): array
    {
        return NewsletterSegment::query()
            ->with('newsletter', 'alsoNewsletter')
            ->orderBy('name')
            ->get()
            ->map(fn (NewsletterSegment $segment) => [
                'id' => $segment->id,
                'name' => $segment->name,
                'newsletter' => $segment->newsletter->name,
                'also' => $segment->alsoNewsletter->name,
            ])
            ->all();
    }

    private function channelOptions(): array
    {
        return collect(Channel::cases())->map(fn (Channel $c) => [
            'value' => $c->value,
            'label' => $c->label(),
        ])->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function mailingListOptions(): array
    {
        return collect(MailingList::cases())->map(fn (MailingList $list) => [
            'value' => $list->value,
            'label' => $list->label(),
        ])->all();
    }
}
