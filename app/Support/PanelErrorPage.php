<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Props for 403/404 pages rendered inside the staff or admin panel layout
 * (#354). Returns null when the request should get the public error page.
 */
final class PanelErrorPage
{
    /**
     * @return array{status: int, title: string, message: string, area: 'Staff'|'Admin', home: string, requiredRole: string|null}|null
     */
    public static function for(Request $request, int $status): ?array
    {
        if (! in_array($status, [403, 404], true)) {
            return null;
        }

        $requested = match (true) {
            $request->is('staff', 'staff/*') => 'Staff',
            $request->is('admin', 'admin/*') => 'Admin',
            default => null,
        };

        /** @var User|null $user */
        $user = $request->user();

        if ($requested === null || ! $user || ! $user->can('access-staff-area')) {
            return null;
        }

        $canAdmin = $user->can('access-admin-area');
        $area = $requested === 'Admin' && ! $canAdmin ? 'Staff' : $requested;
        $roleLabel = ucwords(str_replace('_', ' ', $user->role->value));

        if ($status === 404) {
            return [
                'status' => 404,
                'title' => 'Page not found',
                'message' => "We could not find that page in the {$requested} panel. It may have moved or been removed.",
                'area' => $area,
                'home' => self::home($area),
                'requiredRole' => null,
            ];
        }

        $requiredRole = $requested === 'Admin' && ! $canAdmin ? 'Admin' : null;

        return [
            'status' => 403,
            'title' => 'You do not have access',
            'message' => $requiredRole !== null
                ? "This page needs the {$requiredRole} role. You are signed in as {$roleLabel}."
                : "Your role ({$roleLabel}) cannot open this page. Ask an admin if you need access.",
            'area' => $area,
            'home' => self::home($area),
            'requiredRole' => $requiredRole,
        ];
    }

    private static function home(string $area): string
    {
        return $area === 'Admin' ? '/admin' : '/staff';
    }
}
