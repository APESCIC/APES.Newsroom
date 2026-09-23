<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Enums\Channel;
use App\Enums\ContentVisibility;
use App\Enums\MailingList;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Post;
use App\Services\Publishing\EditorialPostWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPostController extends Controller
{
    public function __construct(private readonly EditorialPostWriter $writer) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Post::query()->with(['author', 'tags'])->latest('updated_at');

        if ($user->role === Role::Staff) {
            $query->where('author_id', $user->id);
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Post $post) => $this->serialize($post))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->isEditableBy($request->user()), 403);
        $post->load(['author', 'tags', 'authors']);

        return response()->json(['data' => $this->serialize($post, full: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request, creating: true);
        $post = $this->writer->create($request->user(), $validated);

        return response()->json(['data' => $this->serialize($post, full: true)], 201);
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->isEditableBy($request->user()), 403);
        $validated = $this->validatePayload($request, creating: false, post: $post);
        $post = $this->writer->update($request->user(), $post, $validated);

        return response()->json(['data' => $this->serialize($post, full: true)]);
    }

    public function publish(Request $request, Post $post): JsonResponse
    {
        $post = $this->writer->publish($request->user(), $post);

        return response()->json(['data' => $this->serialize($post, full: true)]);
    }

    public function unpublish(Request $request, Post $post): JsonResponse
    {
        $post = $this->writer->unpublish($request->user(), $post);

        return response()->json(['data' => $this->serialize($post, full: true)]);
    }

    public function storeToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $issued = ApiToken::issue($request->user(), $validated['name']);

        return response()->json([
            'data' => [
                'id' => $issued['model']->id,
                'name' => $issued['model']->name,
                'token' => $issued['token'],
                'warning' => 'Store this token now; it will not be shown again.',
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $creating, ?Post $post = null): array
    {
        $slugRule = $creating
            ? ['nullable', 'string', 'max:255', 'unique:posts,slug']
            : ['sometimes', 'string', 'max:255', Rule::unique('posts', 'slug')->ignore($post?->id)];

        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => $slugRule,
            'excerpt' => ['nullable', 'string', 'max:1000'],
            'content' => [$creating ? 'required' : 'sometimes', 'array'],
            'channel' => [$creating ? 'required' : 'sometimes', Rule::enum(Channel::class)],
            'visibility' => ['sometimes', Rule::enum(ContentVisibility::class)],
            'hero_image' => ['nullable', 'string', 'max:2048'],
            'hero_image_alt' => ['nullable', 'string', 'max:255'],
            'hero_image_caption' => ['nullable', 'string', 'max:500'],
            'hero_image_credit' => ['nullable', 'string', 'max:255'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'email_on_publish' => ['sometimes', 'boolean'],
            'mailing_lists' => ['nullable', 'array'],
            'mailing_lists.*' => [Rule::enum(MailingList::class)],
            'newsletter_segment_id' => ['nullable', 'integer', 'exists:newsletter_segments,id'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'featured' => ['sometimes', 'boolean'],
            'co_author_ids' => ['nullable', 'array'],
            'co_author_ids.*' => ['integer', 'exists:users,id'],
            'expected_updated_at' => ['nullable', 'string'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Post $post, bool $full = false): array
    {
        $payload = [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => $post->excerpt,
            'status' => $post->status->value,
            'visibility' => $post->visibility instanceof \BackedEnum ? $post->visibility->value : (string) $post->visibility,
            'channel' => $post->channel->value,
            'author_id' => $post->author_id,
            'author' => $post->author?->name,
            'featured' => (bool) $post->featured,
            'published_at' => $post->published_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'tags' => $post->relationLoaded('tags')
                ? $post->tags->map(fn ($tag) => ['name' => $tag->name, 'slug' => $tag->slug])->values()->all()
                : [],
        ];

        if ($full) {
            $payload['content'] = $post->content;
            $payload['hero_image'] = $post->hero_image;
            $payload['hero_image_credit'] = $post->hero_image_credit;
            $payload['meta_title'] = $post->meta_title;
            $payload['meta_description'] = $post->meta_description;
        }

        return $payload;
    }
}
