<?php

namespace Tests\Support;

use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\Passkeys\PasskeyCeremony;
use Illuminate\Http\Request;

trait CreatesPasskeys
{
    protected function fakeAuthenticator(?string $credentialId = null): FakeAuthenticator
    {
        return new FakeAuthenticator(config('passkeys.allowed_origins')[0], $credentialId);
    }

    /**
     * Register a passkey through the real ceremony, outside any HTTP request.
     */
    protected function createPasskey(User $user, string $name = 'Laptop', ?FakeAuthenticator $authenticator = null): Passkey
    {
        $request = Request::create('/');
        $request->setLaravelSession($this->app['session.store']);

        $ceremony = app(PasskeyCeremony::class);
        $options = $ceremony->registrationOptions($request, $user);

        return $ceremony->register($request, $user, ($authenticator ?? $this->fakeAuthenticator())->register($options), $name);
    }
}
