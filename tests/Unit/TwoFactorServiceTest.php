<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\TwoFactor\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TwoFactorService
    {
        return app(TwoFactorService::class);
    }

    private function codeAt(string $secret, int $stepOffset = 0): string
    {
        $google2fa = new Google2FA;

        return $google2fa->oathTotp($secret, $google2fa->getTimestamp() + $stepOffset);
    }

    public function test_setup_stores_an_encrypted_unconfirmed_secret(): void
    {
        $user = User::factory()->create();

        $secret = $this->service()->beginSetup($user);

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertSame($secret, $user->fresh()->two_factor_secret);

        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, (string) $raw);
    }

    public function test_confirming_with_a_valid_code_enables_two_factor_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create();
        $secret = $this->service()->beginSetup($user);

        $codes = $this->service()->confirmSetup($user, $this->codeAt($secret));

        $this->assertIsArray($codes);
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $codes);
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_confirming_with_a_wrong_code_leaves_two_factor_off(): void
    {
        $user = User::factory()->create();
        $this->service()->beginSetup($user);

        $this->assertNull($this->service()->confirmSetup($user, '000000'));
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_codes_within_the_drift_window_are_accepted(): void
    {
        $user = User::factory()->create();
        $secret = $this->service()->beginSetup($user);

        $this->assertTrue($this->service()->verify($user, $this->codeAt($secret, -1)));
    }

    public function test_codes_outside_the_drift_window_are_rejected(): void
    {
        $user = User::factory()->create();
        $secret = $this->service()->beginSetup($user);

        $this->assertFalse($this->service()->verify($user, $this->codeAt($secret, -3)));
        $this->assertFalse($this->service()->verify($user, $this->codeAt($secret, 3)));
    }

    public function test_malformed_codes_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->service()->beginSetup($user);

        foreach (['', '12345', '1234567', 'abcdef'] as $code) {
            $this->assertFalse($this->service()->verify($user, $code), $code);
        }
    }

    public function test_spaces_in_a_code_are_ignored(): void
    {
        $user = User::factory()->create();
        $secret = $this->service()->beginSetup($user);
        $code = $this->codeAt($secret);

        $this->assertTrue($this->service()->verify($user, substr($code, 0, 3).' '.substr($code, 3)));
    }

    public function test_a_code_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $secret = $this->service()->beginSetup($user);
        $code = $this->codeAt($secret);

        $this->assertTrue($this->service()->verify($user, $code));
        $this->assertFalse($this->service()->verify($user->fresh(), $code));
    }

    public function test_an_older_code_is_rejected_after_a_newer_one_was_used(): void
    {
        $user = User::factory()->create();
        $secret = $this->service()->beginSetup($user);

        $this->assertTrue($this->service()->verify($user, $this->codeAt($secret)));
        $this->assertFalse($this->service()->verify($user->fresh(), $this->codeAt($secret, -1)));
    }

    public function test_a_stale_model_cannot_replay_a_code(): void
    {
        $user = User::factory()->create();
        $secret = $this->service()->beginSetup($user);
        $code = $this->codeAt($secret);
        $stale = $user->fresh();

        $this->assertTrue($this->service()->verify($user, $code));
        $this->assertFalse($this->service()->verify($stale, $code));
    }

    public function test_recovery_codes_are_stored_only_as_hashes(): void
    {
        $user = User::factory()->create();

        $codes = $this->service()->replaceRecoveryCodes($user);

        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, array_unique($codes));
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-z2-9]{5}-[a-z2-9]{5}$/', $code);
        }

        $stored = $user->fresh()->two_factor_recovery_codes;
        $this->assertCount(TwoFactorService::RECOVERY_CODE_COUNT, $stored);
        $this->assertEmpty(array_intersect($codes, $stored));

        $raw = (string) DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $raw);
        }
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = User::factory()->create();
        $codes = $this->service()->replaceRecoveryCodes($user);

        $this->assertTrue($this->service()->consumeRecoveryCode($user, $codes[0]));
        $this->assertSame(TwoFactorService::RECOVERY_CODE_COUNT - 1, $this->service()->remainingRecoveryCodes($user));
        $this->assertFalse($this->service()->consumeRecoveryCode($user, $codes[0]));
    }

    public function test_recovery_codes_ignore_case_spaces_and_hyphens(): void
    {
        $user = User::factory()->create();
        $codes = $this->service()->replaceRecoveryCodes($user);

        $this->assertTrue($this->service()->consumeRecoveryCode($user, ' '.strtoupper(str_replace('-', '', $codes[1])).' '));
    }

    public function test_unknown_recovery_codes_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->service()->replaceRecoveryCodes($user);

        $this->assertFalse($this->service()->consumeRecoveryCode($user, 'aaaaa-aaaaa'));
        $this->assertSame(TwoFactorService::RECOVERY_CODE_COUNT, $this->service()->remainingRecoveryCodes($user));
    }

    public function test_regenerating_invalidates_old_recovery_codes(): void
    {
        $user = User::factory()->create();
        $old = $this->service()->replaceRecoveryCodes($user);

        $this->service()->replaceRecoveryCodes($user);

        $this->assertFalse($this->service()->consumeRecoveryCode($user, $old[0]));
    }

    public function test_disabling_clears_all_two_factor_data(): void
    {
        $user = User::factory()->create();
        $secret = $this->service()->beginSetup($user);
        $this->service()->confirmSetup($user, $this->codeAt($secret));

        $this->service()->disable($user);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasTwoFactorEnabled());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertNull($fresh->two_factor_last_used_step);
    }

    public function test_two_factor_fields_are_hidden_from_serialisation(): void
    {
        $user = User::factory()->create();
        $this->service()->beginSetup($user);
        $this->service()->replaceRecoveryCodes($user);

        $array = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('two_factor_secret', $array);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $array);
        $this->assertArrayNotHasKey('two_factor_last_used_step', $array);
    }
}
