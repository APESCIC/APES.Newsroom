<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DevLoginController extends Controller
{
    /**
     * @var array<string, array{email: string, redirect: string}>
     */
    private const ROLES = [
        'public' => [
            'email' => 'public@apes.local',
            'redirect' => '/',
        ],
        'staff' => [
            'email' => 'staff@apes.local',
            'redirect' => '/staff',
        ],
        'admin' => [
            'email' => 'admin@apes.local',
            'redirect' => '/admin',
        ],
        'super_admin' => [
            'email' => 'superadmin@apes.local',
            'redirect' => '/admin',
        ],
    ];

    public function login(Request $request, string $role): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless(array_key_exists($role, self::ROLES), 404);

        $user = User::query()
            ->where('email', self::ROLES[$role]['email'])
            ->firstOrFail();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect(self::ROLES[$role]['redirect']);
    }

    public function logout(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
