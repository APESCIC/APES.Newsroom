<?php

namespace App\Services\Health;

use App\Enums\IntegrationCheckStatus;

/**
 * Outcome of one integration check. Messages are written by the check
 * itself and never echo provider responses or exception text, which can
 * contain key material.
 */
final readonly class CheckResult
{
    public function __construct(
        public IntegrationCheckStatus $status,
        public string $message,
    ) {}

    public static function pass(string $message): self
    {
        return new self(IntegrationCheckStatus::Pass, $message);
    }

    public static function warn(string $message): self
    {
        return new self(IntegrationCheckStatus::Warn, $message);
    }

    public static function fail(string $message): self
    {
        return new self(IntegrationCheckStatus::Fail, $message);
    }
}
