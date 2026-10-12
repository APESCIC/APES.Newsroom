<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Security', [
            'sso_managed' => $user->requiresSso(),
            'has_password' => filled($user->password),
            'status' => session('status'),
        ]);
    }
}
