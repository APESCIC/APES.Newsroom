<?php

namespace App\Services\Mailing;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * The Reply-To used when a newsletter has none of its own (a monitored M365
 * mailbox). Managed on Admin → Settings → Email sending domains.
 */
class DefaultReplyTo
{
    public const KEY = 'mailing.default_reply_to';

    public static function get(): ?string
    {
        $value = Setting::query()->where('key', self::KEY)->value('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The default Reply-To while its domain is still approved and enabled.
     */
    public static function usable(): ?string
    {
        $address = self::get();

        return $address !== null && SenderDomains::replyToProblem($address) === null ? $address : null;
    }

    public static function set(?string $address): void
    {
        $address = filled($address) ? Str::lower(trim((string) $address)) : null;

        if ($address === null) {
            Setting::query()->where('key', self::KEY)->delete();

            return;
        }

        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => $address]);
    }
}
