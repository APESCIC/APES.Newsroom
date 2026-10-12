<?php

namespace App\Services\Auth\TwoFactor;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * Authenticator-app (TOTP) two-factor authentication and recovery codes.
 *
 * Secrets are stored encrypted. Recovery codes are stored only as keyed
 * hashes, so the plain codes exist solely in the response that shows them.
 */
class TwoFactorService
{
    public const RECOVERY_CODE_COUNT = 8;

    /**
     * Accept the previous and next 30-second step to allow for clock drift.
     */
    private const WINDOW = 1;

    /**
     * Unambiguous characters only (no 0/o, 1/l/i).
     */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Start (or restart) setup with a fresh, unconfirmed secret.
     */
    public function beginSetup(User $user): string
    {
        $secret = $this->google2fa->generateSecretKey(32);

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();

        return $secret;
    }

    /**
     * Confirm setup with a code from the authenticator app.
     *
     * @return list<string>|null the plain recovery codes, or null if the code was wrong
     */
    public function confirmSetup(User $user, string $code): ?array
    {
        if ($user->hasTwoFactorEnabled() || ! $this->verify($user, $code)) {
            return null;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return $this->replaceRecoveryCodes($user);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email, $secret);
    }

    /**
     * Render the provisioning QR code locally so the secret never leaves
     * this server.
     */
    public function qrSvg(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($this->otpauthUrl($user, $secret));
    }

    /**
     * Check a six-digit code against the user's secret. A code is accepted
     * once: its time step must be newer than the last accepted one, and the
     * step is claimed atomically so concurrent requests cannot both pass.
     */
    public function verify(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($user->two_factor_secret === null || preg_match('/^\d{6}$/', $code) !== 1) {
            return false;
        }

        $lastStep = (int) ($user->two_factor_last_used_step ?? 0);
        $step = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $lastStep, self::WINDOW);

        if (! is_int($step)) {
            return false;
        }

        $claimed = User::query()
            ->whereKey($user->getKey())
            ->where(fn ($query) => $query->whereNull('two_factor_last_used_step')
                ->orWhere('two_factor_last_used_step', '<', $step))
            ->update(['two_factor_last_used_step' => $step]);

        if ($claimed !== 1) {
            return false;
        }

        $user->forceFill(['two_factor_last_used_step' => $step])->syncOriginalAttribute('two_factor_last_used_step');

        return true;
    }

    /**
     * Replace all recovery codes.
     *
     * @return list<string> the new plain codes
     */
    public function replaceRecoveryCodes(User $user): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = $this->randomChunk().'-'.$this->randomChunk();
        }

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(fn (string $code) => $this->hashRecoveryCode($code), $codes),
        ])->save();

        return $codes;
    }

    /**
     * Use up a recovery code. Each code works once.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hashRecoveryCode($code);

        $consumed = DB::transaction(function () use ($user, $hash): bool {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $hashes = $fresh?->two_factor_recovery_codes ?? [];

            foreach ($hashes as $index => $stored) {
                if (is_string($stored) && hash_equals($stored, $hash)) {
                    unset($hashes[$index]);
                    $fresh->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                    return true;
                }
            }

            return false;
        });

        if ($consumed) {
            $user->refresh();
        }

        return $consumed;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    private function hashRecoveryCode(string $code): string
    {
        $normalised = strtolower(preg_replace('/[\s-]+/', '', $code) ?? '');

        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    private function randomChunk(): string
    {
        $chunk = '';
        $max = strlen(self::RECOVERY_ALPHABET) - 1;

        for ($i = 0; $i < 5; $i++) {
            $chunk .= self::RECOVERY_ALPHABET[random_int(0, $max)];
        }

        return $chunk;
    }
}
