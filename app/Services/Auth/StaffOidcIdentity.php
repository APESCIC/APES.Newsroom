<?php

namespace App\Services\Auth;

final readonly class StaffOidcIdentity
{
    public function __construct(
        public string $sub,
        public string $email,
        public string $name,
        public bool $emailVerified = false,
    ) {}
}
