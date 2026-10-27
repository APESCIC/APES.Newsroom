<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\Account\UserDetailsUpdater;
use App\Services\Admin\AdminUserPresenter;
use App\Services\Admin\UserErasureService;
use App\Support\EmailAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly AdminUserPresenter $presenter) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'method' => ['nullable', Rule::in(['sso', 'password', 'passwordless'])],
            'status' => ['nullable', Rule::in(['verified', 'unverified', 'locked'])],
            'security' => ['nullable', Rule::in(['two_factor', 'passkey', 'none'])],
        ]);

        $filters = [
            'q' => trim((string) ($validated['q'] ?? '')),
            'role' => (string) ($validated['role'] ?? ''),
            'method' => (string) ($validated['method'] ?? ''),
            'status' => (string) ($validated['status'] ?? ''),
            'security' => (string) ($validated['security'] ?? ''),
        ];

        $users = $this->query($filters)
            ->withCount('passkeys')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user) => $this->presenter->row($user));

        return Inertia::render('Admin/Users/Index', [
            'filters' => $filters,
            'users' => $users,
            'roles' => collect(Role::cases())->map(fn (Role $role) => $role->value)->values(),
        ]);
    }

    public function show(Request $request, User $user): Response
    {
        Gate::authorize('view', $user);

        return Inertia::render('Admin/Users/Show', [
            'user' => $this->presenter->detail($user, $request->user()),
        ]);
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('Admin/Users/Edit', [
            'user' => $this->presenter->editable($user),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UserDetailsUpdater $updater): RedirectResponse
    {
        $result = $updater->update($request->user(), $user, $request->validated(), $request);

        return to_route('admin.users.show', $user)->with('status', UserDetailsUpdater::message($result));
    }

    public function destroy(Request $request, User $user, UserErasureService $erasure): RedirectResponse
    {
        Gate::authorize('erase', $user);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'confirm_email' => ['required', 'string'],
        ]);

        if (EmailAddress::normalize($validated['confirm_email']) !== EmailAddress::normalize((string) $user->email)) {
            throw ValidationException::withMessages(['confirm_email' => 'Type the user\'s email address exactly to confirm.']);
        }

        $mode = $erasure->erase($user, $request->user(), trim($validated['reason']), $request);

        return to_route('admin.users.index')->with('status', $mode === UserErasureService::MODE_DELETED
            ? 'User deleted.'
            : 'User anonymised and locked. Their authored content is kept under "Deleted user".');
    }

    /**
     * @param  array{q: string, role: string, method: string, status: string, security: string}  $filters
     * @return Builder<User>
     */
    private function query(array $filters): Builder
    {
        $query = User::query();

        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        if ($filters['role'] !== '') {
            $query->where('role', $filters['role']);
        }

        match ($filters['method']) {
            'sso' => $query->where('auth_provider', 'cloudron_oidc'),
            'password' => $query->where('auth_provider', '!=', 'cloudron_oidc')->whereNotNull('password'),
            'passwordless' => $query->where('auth_provider', '!=', 'cloudron_oidc')->whereNull('password'),
            default => null,
        };

        match ($filters['status']) {
            'verified' => $query->where(fn (Builder $inner) => $inner->whereNotNull('email_verified_at')->orWhere('auth_provider', 'cloudron_oidc')),
            'unverified' => $query->whereNull('email_verified_at')->where('auth_provider', '!=', 'cloudron_oidc'),
            'locked' => $query->whereNotNull('locked_at'),
            default => null,
        };

        match ($filters['security']) {
            'two_factor' => $query->whereNotNull('two_factor_confirmed_at')->whereNotNull('two_factor_secret'),
            'passkey' => $query->has('passkeys'),
            'none' => $query->where(fn (Builder $inner) => $inner->whereNull('two_factor_confirmed_at')->orWhereNull('two_factor_secret'))->doesntHave('passkeys'),
            default => null,
        };

        return $query;
    }
}
