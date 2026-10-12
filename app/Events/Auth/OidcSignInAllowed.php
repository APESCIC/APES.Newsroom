<?php

namespace App\Events\Auth;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class OidcSignInAllowed
{
    use Dispatchable;

    /**
     * @param  list<string>  $amr
     */
    public function __construct(public readonly User $user, public readonly array $amr = []) {}
}
