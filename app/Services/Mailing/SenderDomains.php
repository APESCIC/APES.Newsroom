<?php

namespace App\Services\Mailing;

use App\Enums\SendingDomainStatus;
use App\Models\EmailSendingDomain;
use Illuminate\Support\Str;

/**
 * Admin-approved sending domains (Admin → Settings → Email sending domains).
 * From addresses need an enabled, verified domain; reply-to addresses only
 * need an enabled one, because replies do not go through Mailgun.
 */
class SenderDomains
{
    public const RESERVED_LOCAL_PARTS = ['postmaster', 'abuse', 'hostmaster', 'webmaster', 'mailer-daemon'];

    /**
     * Enabled (approved) domains.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        return EmailSendingDomain::query()->enabled()->orderBy('domain')->pluck('domain')->all();
    }

    /**
     * Enabled domains Mailgun has verified.
     *
     * @return list<string>
     */
    public static function verified(): array
    {
        return EmailSendingDomain::query()
            ->where('status', SendingDomainStatus::Verified->value)
            ->orderBy('domain')
            ->pluck('domain')
            ->all();
    }

    public static function domainOf(string $email): string
    {
        return Str::lower(Str::after(trim($email), '@'));
    }

    public static function localPartOf(string $email): string
    {
        return Str::lower(Str::before(trim($email), '@'));
    }

    /**
     * Why a From address cannot be used, or null when it can.
     */
    public static function senderProblem(?string $email): ?string
    {
        if (blank($email)) {
            return null;
        }

        $domain = self::find($email);
        $name = self::domainOf((string) $email);

        return match (true) {
            $domain === null => "The sender domain {$name} is not an approved sending domain.",
            $domain->status === SendingDomainStatus::Disabled => "The sender domain {$name} has been disabled by an admin.",
            $domain->status !== SendingDomainStatus::Verified => "The sender domain {$name} is not verified in Mailgun.",
            default => null,
        };
    }

    /**
     * Why a reply-to address cannot be used, or null when it can.
     */
    public static function replyToProblem(?string $email): ?string
    {
        if (blank($email)) {
            return null;
        }

        $domain = self::find($email);
        $name = self::domainOf((string) $email);

        return match (true) {
            $domain === null => "The reply-to domain {$name} is not an approved sending domain.",
            $domain->status === SendingDomainStatus::Disabled => "The reply-to domain {$name} has been disabled by an admin.",
            default => null,
        };
    }

    /**
     * Why a local part (before the @) is not allowed, or null.
     */
    public static function localPartProblem(string $email): ?string
    {
        $local = self::localPartOf($email);

        if ($local === '' || preg_match('/\s/', $local) === 1) {
            return 'The part before the @ cannot be empty or contain spaces.';
        }

        if (in_array($local, self::RESERVED_LOCAL_PARTS, true)) {
            return "The address {$local}@ is reserved for mail system use. Choose a different name.";
        }

        return null;
    }

    private static function find(string $email): ?EmailSendingDomain
    {
        return EmailSendingDomain::query()->where('domain', self::domainOf($email))->first();
    }
}
