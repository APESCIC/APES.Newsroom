<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreRegisteredUserRequest;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\Mailing\ConsentService;
use App\Services\Membership\MembershipService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly ConsentService $consent,
    ) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Register', [
            'newsletters' => Newsletter::query()
                ->active()
                ->where('subscribe_on_signup', true)
                ->orderBy('name')
                ->get(['id', 'name', 'description']),
        ]);
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

        $this->consent->requestAtRegistration(
            $user,
            $validated['newsletter_ids'] ?? [],
            $request->ip(),
            $request->userAgent(),
        );

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('verification.notice');
    }
}
