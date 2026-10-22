<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminUserPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
            'status' => ['nullable', Rule::in(['verified', 'unverified'])],
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
            'user' => $this->presenter->detail($user),
        ]);
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
