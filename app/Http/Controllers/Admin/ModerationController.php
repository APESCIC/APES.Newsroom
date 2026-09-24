<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\ModerationReport;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use App\Services\Engagement\CommentService;
use App\Services\Engagement\ProfileService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ModerationController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(
        private readonly ProfileService $profiles,
        private readonly CommentService $comments,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        $q = trim((string) $request->query('q', ''));

        $pendingProfilesQuery = Profile::query()
            ->where('moderation_status', ModerationStatus::Pending)
            ->with('user:id,name,email')
            ->latest('updated_at');

        $pendingCommentsQuery = Comment::query()
            ->where('moderation_status', ModerationStatus::Pending)
            ->with(['user:id,name', 'post:id,title,slug'])
            ->latest();

        $reportsQuery = ModerationReport::query()
            ->where('status', 'open')
            ->with('reporter:id,name')
            ->latest();

        $suspendedQuery = Profile::query()
            ->where('moderation_status', ModerationStatus::Suspended)
            ->with('user:id,name')
            ->latest('moderated_at');

        if ($q !== '') {
            $like = '%'.$q.'%';
            $pendingProfilesQuery->where(function ($query) use ($like) {
                $query->where('display_name', 'like', $like)
                    ->orWhere('bio', 'like', $like)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like)->orWhere('email', 'like', $like));
            });
            $pendingCommentsQuery->where(function ($query) use ($like) {
                $query->where('body', 'like', $like)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like))
                    ->orWhereHas('post', fn ($post) => $post->where('title', 'like', $like));
            });
            $reportsQuery->where(function ($query) use ($like) {
                $query->where('reason', 'like', $like)
                    ->orWhereHas('reporter', fn ($user) => $user->where('name', 'like', $like));
            });
            $suspendedQuery->where(function ($query) use ($like) {
                $query->where('display_name', 'like', $like)
                    ->orWhere('moderation_notes', 'like', $like)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like));
            });
        }

        $pendingProfiles = $pendingProfilesQuery
            ->paginate(self::PER_PAGE, ['*'], 'profiles_page')
            ->withQueryString()
            ->through(fn (Profile $profile) => [
                'id' => $profile->id,
                'display_name' => $profile->display_name,
                'bio' => $profile->bio,
                'user_name' => $profile->user->name,
                'updated_at' => $profile->updated_at?->toIso8601String(),
            ]);

        $pendingComments = $pendingCommentsQuery
            ->paginate(self::PER_PAGE, ['*'], 'comments_page')
            ->withQueryString()
            ->through(fn (Comment $comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user_name' => $comment->user->name,
                'post_title' => $comment->post->title,
                'post_slug' => $comment->post->slug,
                'created_at' => $comment->created_at?->toIso8601String(),
            ]);

        $reports = $reportsQuery
            ->paginate(self::PER_PAGE, ['*'], 'reports_page')
            ->withQueryString()
            ->through(fn (ModerationReport $report) => [
                'id' => $report->id,
                'reason' => $report->reason,
                'reportable_type' => class_basename($report->reportable_type),
                'reportable_id' => $report->reportable_id,
                'reporter' => $report->reporter?->name,
                'created_at' => $report->created_at?->toIso8601String(),
            ]);

        $suspendedProfiles = $suspendedQuery
            ->paginate(self::PER_PAGE, ['*'], 'suspended_page')
            ->withQueryString()
            ->through(fn (Profile $profile) => [
                'id' => $profile->id,
                'display_name' => $profile->display_name,
                'user_name' => $profile->user->name,
                'notes' => $profile->moderation_notes,
            ]);

        return Inertia::render('Admin/Moderation/Index', [
            'profiles' => $this->pagePayload($pendingProfiles),
            'comments' => $this->pagePayload($pendingComments),
            'reports' => $this->pagePayload($reports),
            'suspended' => $this->pagePayload($suspendedProfiles),
            'filters' => [
                'q' => $q,
            ],
            'counts' => [
                'profiles' => $pendingProfiles->total(),
                'comments' => $pendingComments->total(),
                'reports' => $reports->total(),
                'suspended' => $suspendedProfiles->total(),
            ],
        ]);
    }

    public function moderateProfile(Request $request, Profile $profile): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                ModerationStatus::Approved->value,
                ModerationStatus::Rejected->value,
                ModerationStatus::Suspended->value,
                ModerationStatus::Private->value,
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->profiles->moderate(
            $profile,
            $request->user(),
            ModerationStatus::from($validated['status']),
            $validated['notes'] ?? null,
        );

        $this->audit->record($request->user(), 'profile.moderated', $profile, [
            'status' => $validated['status'],
        ], $request);

        return back();
    }

    public function moderateComment(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                ModerationStatus::Approved->value,
                ModerationStatus::Rejected->value,
            ])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->comments->moderate(
            $comment,
            $request->user(),
            ModerationStatus::from($validated['status']),
            $validated['notes'] ?? null,
        );

        $this->audit->record($request->user(), 'comment.moderated', $comment, [
            'status' => $validated['status'],
        ], $request);

        return back();
    }

    public function resolveReport(Request $request, ModerationReport $report): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'status' => ['required', Rule::in(['resolved', 'dismissed'])],
        ]);

        $report->update(['status' => $validated['status']]);

        $this->audit->record($request->user(), 'report.resolved', $report, [
            'status' => $validated['status'],
        ], $request);

        return back();
    }

    public function restoreComment(Request $request, int $comment): RedirectResponse
    {
        $this->authorizeAdmin();

        $model = Comment::withTrashed()->findOrFail($comment);

        if ($model->trashed()) {
            $model->restore();
        }

        $model->update([
            'moderation_status' => ModerationStatus::Approved,
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ]);

        $this->audit->record($request->user(), 'comment.restored', $model, [], $request);

        return back();
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}, links: array{prev: string|null, next: string|null}}
     */
    private function pagePayload(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_values($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }

    private function authorizeAdmin(): void
    {
        if (! request()->user()?->role->atLeast(Role::Admin)) {
            abort(403);
        }
    }
}
