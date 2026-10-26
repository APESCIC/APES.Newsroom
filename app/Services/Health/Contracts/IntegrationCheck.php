<?php

namespace App\Services\Health\Contracts;

use App\Services\Health\CheckResult;

/**
 * A safe, read-only connectivity check for one integration provider.
 * Implementations must time out quickly and never send email, create
 * charges or change provider state.
 */
interface IntegrationCheck
{
    public function provider(): string;

    /**
     * @param  array<string, string>  $overrides  Unsaved values to test instead of the stored ones, keyed by key name.
     */
    public function configured(array $overrides = []): bool;

    /**
     * @param  array<string, string>  $overrides  Unsaved values to test instead of the stored ones, keyed by key name.
     */
    public function run(array $overrides = []): CheckResult;
}
