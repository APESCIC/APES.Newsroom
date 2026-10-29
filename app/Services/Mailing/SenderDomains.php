<?php

namespace App\Services\Mailing;

use App\Enums\SenderAddressPurpose;
use App\Enums\SendingDomainStatus;
use App\Models\EmailSenderAddress;
use App\Models\EmailSendingDomain;
use App\Services\Mail\MailgunDomainService;
use App\Services\Mail\MailTransports;
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
     * Why a From address is not on the admin's list of allowed addresses, or
     * null when it is listed and enabled.
     */
    public static function senderAddressProblem(?string $email): ?string
    {
        if (blank($email)) {
            return null;
        }

        $normalized = Str::lower(trim((string) $email));
        $address = EmailSenderAddress::query()->where('address', $normalized)->first();

        return match (true) {
            $address === null => "{$normalized} is not an allowed From address. An admin can add it under Settings → Email sending domains.",
            ! $address->enabled => "{$normalized} has been disabled as a From address by an admin.",
            $address->purpose !== SenderAddressPurpose::News => "{$normalized} is a no-reply address. Choose a newsletter From address.",
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

    /**
     * The approved domain an address belongs to: its own domain, or the domain
     * whose Mailgun domain it uses (news@news.myapes.me.uk under myapes.me.uk).
     */
    public static function find(string $email): ?EmailSendingDomain
    {
        $name = self::domainOf($email);
        if ($name === '') {
            return null;
        }

        return EmailSendingDomain::query()->where('domain', $name)->first()
            ?? EmailSendingDomain::query()->where('mailgun_domain', $name)->orderBy('id')->first();
    }

    /**
     * The Mailgun domain to send a From address through, or null when its
     * domain is not approved and enabled.
     */
    public static function mailgunDomainForSender(string $email, MailgunDomainService $mailgun): ?string
    {
        $domain = self::find($email);
        if ($domain === null || ! $domain->isEnabled()) {
            return null;
        }

        return $mailgun->mailgunDomainFor($domain->domain, $domain->mailgun_domain);
    }

    /**
     * The mailer newsletters use (Admin → Settings → Mail sending); null is
     * the default mailer.
     */
    public static function newsletterMailer(): ?string
    {
        return app(MailTransports::class)->newsletterMailer();
    }
}
