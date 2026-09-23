<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreRegisteredUserRequest;
use App\Models\User;
use App\Services\Membership\MembershipService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(StoreRegisteredUserRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'auth_provider' => 'password',
        ]);

        $user->forceFill(['role' => Role::Public])->save();

        $this->memberships->provisionFreeMember($user);

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('verification.notice');
    }
}
