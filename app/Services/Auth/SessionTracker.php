<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserSession;
use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Keeps user_sessions in step with signed-in sessions. Each session holds
 * a random key; only its hash is stored, so this works with any session
 * driver and the table cannot be used to hijack a session.
 */
class SessionTracker
{
    public const SESSION_KEY = 'device_session_key';

    public const DEVICE_COOKIE = 'newsroom_device';

    private const TOUCH_INTERVAL_SECONDS = 300;

    public function start(User $user, Request $request, bool $remembered): UserSession
    {
        // A "remember me" sign-in starts a new session on the same browser;
        // the device cookie lets it carry on the existing record.
        $existing = $remembered ? $this->rememberedDevice($user, $request) : null;

        $key = Str::random(64);
        $request->session()->put(self::SESSION_KEY, $key);

        if ($remembered) {
            Cookie::queue(self::DEVICE_COOKIE, $key, (int) config('auth.guards.web.remember', 43200));
        }

        $attributes = [
            'session_key_hash' => $this->hash($key),
            'ip_address' => $request->ip(),
            'user_agent' => filled($request->userAgent()) ? Str::limit((string) $request->userAgent(), 509) : null,
            'remembered' => $remembered,
            'last_seen_at' => now(),
            ...$this->describe($request->userAgent()),
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }

        return $user->sessions()->create($attributes);
    }

    public function current(Request $request): ?UserSession
    {
        $key = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (! is_string($key) || $key === '') {
            return null;
        }

        return UserSession::query()->where('session_key_hash', $this->hash($key))->first();
    }

    public function hasKey(Request $request): bool
    {
        return $request->hasSession() && filled($request->session()->get(self::SESSION_KEY));
    }

    public function touch(UserSession $session, Request $request): void
    {
        if ($session->last_seen_at !== null && $session->last_seen_at->diffInSeconds(now()) < self::TOUCH_INTERVAL_SECONDS) {
            return;
        }

        $session->forceFill([
            'last_seen_at' => now(),
            'ip_address' => $request->ip(),
        ])->save();
    }

    public function end(UserSession $session): void
    {
        if ($session->revoked_at === null) {
            $session->forceFill(['revoked_at' => now()])->save();
        }
    }

    /**
     * Mark every live session for the user as ended, optionally keeping one.
     */
    public function endAll(User $user, ?int $exceptId = null): int
    {
        return $user->sessions()
            ->whereNull('revoked_at')
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['revoked_at' => now()]);
    }

    /**
     * @return array{browser: ?string, platform: ?string, device_type: ?string}
     */
    public function describe(?string $userAgent): array
    {
        if (blank($userAgent)) {
            return ['browser' => null, 'platform' => null, 'device_type' => null];
        }

        $detector = new DeviceDetector((string) $userAgent);
        $detector->discardBotInformation();
        $detector->parse();

        if ($detector->isBot()) {
            return ['browser' => 'Automated client', 'platform' => null, 'device_type' => 'bot'];
        }

        $browser = $detector->getClient('name');
        $platform = $detector->getOs('name');
        $device = $detector->getDeviceName();

        return [
            'browser' => is_string($browser) && $browser !== '' && $browser !== DeviceDetector::UNKNOWN ? Str::limit($browser, 97) : null,
            'platform' => is_string($platform) && $platform !== '' && $platform !== DeviceDetector::UNKNOWN ? Str::limit($platform, 97) : null,
            'device_type' => $device !== '' ? Str::limit($device, 29) : null,
        ];
    }

    public function forgetDevice(): void
    {
        Cookie::queue(Cookie::forget(self::DEVICE_COOKIE));
    }

    private function rememberedDevice(User $user, Request $request): ?UserSession
    {
        $key = $request->cookie(self::DEVICE_COOKIE);

        if (! is_string($key) || $key === '') {
            return null;
        }

        return $user->sessions()
            ->live()
            ->where('remembered', true)
            ->where('session_key_hash', $this->hash($key))
            ->first();
    }

    private function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
