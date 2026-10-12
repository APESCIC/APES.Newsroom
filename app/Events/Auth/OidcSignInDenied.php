<?php

namespace App\Events\Auth;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class OidcSignInDenied
{
    use Dispatchable;

    /**
     * @param  string  $reason  Short machine-readable category, never provider error text.
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?User $user = null,
    ) {}
}
