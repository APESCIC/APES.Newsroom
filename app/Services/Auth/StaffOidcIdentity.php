<?php

namespace App\Services\Auth;

final readonly class StaffOidcIdentity
{
    /**
     * @param  list<string>  $amr  Authentication method references from the ID token (RFC 8176); empty when the provider does not report them.
     */
    public function __construct(
        public string $sub,
        public string $email,
        public string $name,
        public bool $emailVerified = false,
        public ?int $authTime = null,
        public array $amr = [],
    ) {}
}
