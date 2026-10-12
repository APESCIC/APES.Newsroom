<?php

namespace App\Services\Auth\Passkeys;

use App\Models\Passkey;

final readonly class PasskeyAssertion
{
    public function __construct(
        public Passkey $passkey,
        public bool $userVerified,
    ) {}
}
