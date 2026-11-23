<?php

namespace App\Support\Dev;

use App\Enums\Role;
use App\Models\User;
use App\Support\DemoAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Local-only developer sign-in and role switcher for the three demo accounts.
 * Mirrors Intranet QA login behaviour, without role stacks.
 */
final class DevLogin
{
    /** Flashed for the request straight after a role switch. */
    public const SWITCHED = 'dev.switched';

    /** Flashed when a page needs a fresh confirmation of the current role. */
    public const CONFIRM = 'dev.confirm';

    /**
     * @var array<string, array{email: string, role: Role, label: string, redirect: string}>
     */
    private const ROLES = [
        'public' => [
            'email' => DemoAccounts::MEMBER,
            'role' => Role::Public,
            'label' => 'Public',
            'redirect' => '/',
        ],
        'staff' => [
            'email' => DemoAccounts::STAFF,
            'role' => Role::Staff,
            'label' => 'Staff',
            'redirect' => '/staff',
        ],
        'admin' => [
            'email' => DemoAccounts::ADMIN,
            'role' => Role::Admin,
            'label' => 'Admin',
            'redirect' => '/admin',
        ],
    ];

    public static function enabled(): bool
    {
        return config('newsroom.dev_login_enabled') === true && app()->environment('local');
    }

    /**
     * @return list<array{key: string, label: string, email: string}>
     */
    public static function picker(): array
    {
        $profiles = [];

        foreach (self::ROLES as $key => $role) {
            $profiles[] = [
                'key' => $key,
                'label' => $role['label'],
                'email' => $role['email'],
            ];
        }

        return $profiles;
    }

    /**
     * @return array<string, array{email: string, role: Role, label: string, redirect: string}>
     */
    public static function roles(): array
    {
        return self::ROLES;
    }

    public static function profileFor(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        foreach (self::ROLES as $key => $role) {
            if ($user->email === $role['email']) {
                return $key;
            }
        }

        return null;
    }

    public static function label(string $profile): string
    {
        return self::ROLES[$profile]['label'] ?? $profile;
    }

    public static function redirectFor(string $profile): string
    {
        return self::ROLES[$profile]['redirect'] ?? '/';
    }

    /**
     * A path on this site to return to, or null if it could lead elsewhere.
     */
    public static function safeReturnPath(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || strlen($path) > 2000) {
            return null;
        }

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\\\\\s]/', $path) === 1) {
            return null;
        }

        return $path;
    }

    /**
     * After a role switch, send the person home when the new role cannot open the page.
     */
    public static function fallbackAfterSwitch(Request $request, int $status): ?RedirectResponse
    {
        if (! in_array($status, [403, 404], true) || ! $request->isMethod('GET') || ! $request->hasSession() || ! self::enabled()) {
            return null;
        }

        if ($request->session()->pull(self::SWITCHED) !== true) {
            return null;
        }

        $profile = self::profileFor($request->user());
        $home = $profile !== null ? self::redirectFor($profile) : '/';

        return redirect($home)
            ->with('status', 'Your new developer role cannot open that page, so you are here instead.');
    }

    public static function userFor(string $profile): User
    {
        $role = self::ROLES[$profile] ?? throw new InvalidArgumentException("Unknown developer profile [{$profile}].");

        $user = User::query()->updateOrCreate(
            ['email' => $role['email']],
            [
                'name' => $role['label'].' Demo',
                'password' => DemoAccounts::PASSWORD,
                'email_verified_at' => now(),
                'auth_provider' => 'password',
            ],
        );

        $user->forceFill(['role' => $role['role']])->save();

        return $user->fresh();
    }
}
