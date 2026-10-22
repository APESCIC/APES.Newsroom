<?php

namespace App\Services\Admin;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Passkey;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Audit\AuditLogQuery;
use App\Services\Auth\TwoFactor\TwoFactorService;

/**
 * Explicit, allow-listed payloads for the admin user screens. Never pass a
 * User model straight to Inertia: hashes and secrets must not leave the server.
 */
class AdminUserPresenter
{
    private const RECENT_EVENTS = 20;

    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * @return array<string, mixed>
     */
    public function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'sign_in_method' => self::signInMethod($user),
            'email_verified' => $user->hasVerifiedEmail(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'passkeys_count' => (int) ($user->passkeys_count ?? $user->passkeys()->count()),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(User $user): array
    {
        return [
            ...$this->row($user),
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'sso_managed' => $user->requiresSso(),
            'role_source' => $user->auth_provider === 'cloudron_oidc'
                ? ['type' => 'directory', 'groups' => array_values($user->ldap_group_snapshot ?? [])]
                : ['type' => 'in_app', 'groups' => []],
            'recovery_codes_remaining' => $user->hasTwoFactorEnabled() ? $this->twoFactor->remainingRecoveryCodes($user) : 0,
            'passkeys' => $user->passkeys()->oldest()->get()->map(fn (Passkey $passkey) => [
                'id' => $passkey->getKey(),
                'name' => $passkey->name,
                'created_at' => $passkey->created_at?->toIso8601String(),
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            ])->values()->all(),
            'sessions' => $user->sessions()->live()->latest('last_seen_at')->get()->map(fn (UserSession $session) => [
                'id' => $session->getKey(),
                'browser' => $session->browser,
                'platform' => $session->platform,
                'device_type' => $session->device_type,
                'ip_address' => $session->ip_address,
                'remembered' => $session->remembered,
                'last_seen_at' => $session->last_seen_at?->toIso8601String(),
            ])->values()->all(),
            'api_tokens' => $user->apiTokens()->latest()->get()->map(fn (ApiToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ])->values()->all(),
            'security_events' => $this->securityEvents($user),
        ];
    }

    public static function signInMethod(User $user): string
    {
        if ($user->auth_provider === 'cloudron_oidc') {
            return 'sso';
        }

        return filled($user->password) ? 'password' : 'passwordless';
    }

    /**
     * Account changes made by or to the user, excluding sign-in history.
     *
     * @return list<array<string, mixed>>
     */
    private function securityEvents(User $user): array
    {
        return AuditLog::query()
            ->with('actor:id,name,email')
            ->where(fn ($query) => $query
                ->where(fn ($own) => $own->where('actor_id', $user->id)->where('action', 'like', 'account.%'))
                ->orWhere(fn ($subject) => $subject
                    ->where('subject_type', $user->getMorphClass())
                    ->where('subject_id', $user->id)
                    ->where('action', 'not like', 'auth.%')))
            ->latest('id')
            ->limit(self::RECENT_EVENTS)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'created_at' => $log->created_at?->toIso8601String(),
                'actor' => $log->actor?->only(['id', 'name', 'email']),
                'ip_address' => $log->ip_address,
                'payload' => AuditLogQuery::payloadPairs($log->payload),
            ])
            ->values()
            ->all();
    }
}
