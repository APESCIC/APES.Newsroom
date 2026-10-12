<?php

namespace Tests\Support;

use App\Models\User;
use App\Services\Auth\RecentAuthentication;
use App\Services\Auth\TwoFactor\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;

trait InteractsWithTwoFactor
{
    /**
     * @return list<string> the plain recovery codes
     */
    protected function enableTwoFactor(User $user): array
    {
        $service = app(TwoFactorService::class);
        $service->beginSetup($user);
        $codes = $service->confirmSetup($user, $this->totpFor($user));
        $this->assertNotNull($codes);

        $user->forceFill(['two_factor_last_used_step' => null])->save();

        return $codes;
    }

    protected function totpFor(User $user, int $stepOffset = 0): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp((string) $user->fresh()->two_factor_secret, $google2fa->getTimestamp() + $stepOffset);
    }

    /**
     * @return array<string, int>
     */
    protected function recentlyConfirmed(): array
    {
        return [RecentAuthentication::SESSION_KEY => now()->getTimestamp()];
    }
}
