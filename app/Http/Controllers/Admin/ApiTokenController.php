<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Notifications\Security\ApiTokenCreatedNotification;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    public const CREATED_FLASH = 'api_token_created';

    public const EXPIRY_DAYS = [30, 90, 180, 365];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ApiToken::class);

        return Inertia::render('Admin/ApiTokens/Index', [
            'tokens' => ApiToken::query()
                ->with('user:id,name,email')
                ->latest('id')
                ->get()
                ->map(fn (ApiToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'owner' => $token->user?->only(['id', 'name', 'email']),
                    'abilities' => $token->abilities,
                    'status' => $token->status(),
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'revoked_at' => $token->revoked_at?->toIso8601String(),
                ])
                ->values(),
            'owners' => User::query()
                ->whereIn('role', [Role::Staff, Role::Admin, Role::SuperAdmin])
                ->whereNull('locked_at')
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => $user->only(['id', 'name', 'email']))
                ->values(),
            'abilities' => collect(ApiToken::ABILITIES)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'expiryDays' => self::EXPIRY_DAYS,
            'created' => $request->session()->get(self::CREATED_FLASH),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ApiToken::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'distinct', Rule::in(array_keys(ApiToken::ABILITIES))],
            'expires_in_days' => ['required', 'integer', Rule::in(self::EXPIRY_DAYS)],
        ]);

        $owner = User::query()->findOrFail($validated['owner_id']);

        if (! $owner->role->atLeast(Role::Staff) || $owner->isLocked()) {
            throw ValidationException::withMessages(['owner_id' => 'Tokens can only belong to active staff accounts.']);
        }

        $issued = ApiToken::issue(
            $owner,
            $validated['name'],
            array_values($validated['abilities']),
            now()->addDays((int) $validated['expires_in_days']),
        );

        $this->audit->record($request->user(), 'api_token.issued', $issued['model'], [
            'name' => $issued['model']->name,
            'owner_id' => $owner->id,
            'abilities' => $issued['model']->abilities,
            'expires_at' => $issued['model']->expires_at?->toIso8601String(),
            'source' => 'admin',
        ], $request);
        $owner->notify(new ApiTokenCreatedNotification($issued['model']->name));

        return redirect()->route('admin.api-tokens.index')->with(self::CREATED_FLASH, [
            'id' => $issued['model']->id,
            'name' => $issued['model']->name,
            'token' => $issued['token'],
        ]);
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        Gate::authorize('revoke', ApiToken::class);
        $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason']);

        if (! $apiToken->isRevoked()) {
            $apiToken->forceFill(['revoked_at' => now()])->save();

            $this->audit->record($request->user(), 'api_token.revoked', $apiToken, [
                'name' => $apiToken->name,
                'owner_id' => $apiToken->user_id,
                'reason' => $reason,
                'source' => 'admin',
            ], $request);
        }

        return redirect()->route('admin.api-tokens.index')->with('status', "Token \"{$apiToken->name}\" revoked.");
    }
}
