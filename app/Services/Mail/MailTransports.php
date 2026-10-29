<?php

namespace App\Services\Mail;

use App\Services\Integrations\IntegrationSecrets;
use App\Services\Settings\SettingsRepository;
use App\Support\Integrations\IntegrationDefinitions;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Mail\MailManager;
use Throwable;

/**
 * Which mailer system mail and newsletters use, from the Mail sending
 * settings over the environment default (Cloudron SMTP on Cloudron).
 *
 * Unset settings keep the starting setup: system mail on Cloudron SMTP,
 * newsletters on the Mailgun API when its key is configured, no fallback.
 * Newsletters never fall back to another transport.
 */
class MailTransports
{
    public const CLOUDRON_SMTP = 'cloudron_smtp';

    public const MAILGUN_API = 'mailgun_api';

    public const CUSTOM_SMTP = 'custom_smtp';

    public const NONE = 'none';

    /** ZeptoMail is transactional only, so it must not carry newsletters. */
    public const TRANSACTIONAL_ONLY_HOSTS = ['smtp.zeptomail.eu', 'smtp.zeptomail.com', 'smtp.zeptomail.in'];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly IntegrationSecrets $secrets,
    ) {}

    public function systemTransport(): string
    {
        return $this->setting(SettingDefinitions::MAIL_SYSTEM_TRANSPORT, self::CLOUDRON_SMTP);
    }

    public function newsletterTransport(): string
    {
        return $this->setting(SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT, self::MAILGUN_API);
    }

    public function systemFallback(): string
    {
        return $this->setting(SettingDefinitions::MAIL_SYSTEM_FALLBACK, self::NONE);
    }

    /**
     * The mailer newsletters send through; null is the default mailer.
     * While the setting is unset and Mailgun has no key, newsletters stay on
     * the environment mailer as before; a chosen transport is always used,
     * so a broken one pauses sends instead of silently switching.
     */
    public function newsletterMailer(): ?string
    {
        $mailer = $this->mailerFor($this->effectiveNewsletterTransport());

        return $mailer === config('mail.default') ? null : $mailer;
    }

    /**
     * The transport newsletters actually use right now.
     */
    public function effectiveNewsletterTransport(): string
    {
        $transport = $this->newsletterTransport();

        return $transport === self::MAILGUN_API && ! $this->isConfigured(self::MAILGUN_API) && ! $this->isStored(SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT)
            ? self::CLOUDRON_SMTP
            : $transport;
    }

    /**
     * The mailer name for a transport, configuring Custom SMTP from the
     * stored integration values first.
     */
    public function mailerFor(string $transport): string
    {
        return match ($transport) {
            self::MAILGUN_API => 'mailgun-api',
            self::CUSTOM_SMTP => $this->configureCustomSmtp(),
            default => $this->environmentMailer(),
        };
    }

    /**
     * Point the default mailer at the system transport (wrapped in a
     * failover when a fallback is chosen). Safe before the database exists.
     */
    public function applySystemMailer(?object $manager = null): void
    {
        $environment = $this->environmentMailer();

        try {
            $system = $this->mailerFor($this->systemTransport());
            $fallback = $this->systemFallback();
            $fallbackMailer = $fallback === self::NONE ? null : $this->mailerFor($fallback);
        } catch (Throwable) {
            $system = $environment;
            $fallbackMailer = null;
        }

        if ($fallbackMailer !== null && $fallbackMailer !== $system) {
            config(['mail.mailers.system-failover.mailers' => [$system, $fallbackMailer]]);
            $system = 'system-failover';
        }

        config(['mail.default' => $system]);
        if ($manager instanceof MailManager) {
            $manager->purge('custom-smtp');
            $manager->purge('system-failover');
        }
    }

    /**
     * Why a transport cannot be used, or null when it can.
     */
    public function unavailableReason(string $transport, bool $forNewsletters = false): ?string
    {
        return match ($transport) {
            self::MAILGUN_API => $this->isConfigured(self::MAILGUN_API) ? null : 'The Mailgun API key is not set under Integrations.',
            self::CUSTOM_SMTP => match (true) {
                ! $this->isConfigured(self::CUSTOM_SMTP) => 'Custom SMTP needs a host and password under Integrations.',
                $forNewsletters && $this->isTransactionalOnlyHost() => 'ZeptoMail is for transactional mail only, so it cannot send newsletters.',
                default => null,
            },
            default => null,
        };
    }

    public function isConfigured(string $transport): bool
    {
        return match ($transport) {
            self::MAILGUN_API => $this->secrets->configured(IntegrationDefinitions::MAILGUN, 'api_key'),
            self::CUSTOM_SMTP => $this->secrets->configured(IntegrationDefinitions::CUSTOM_SMTP, 'host')
                && $this->secrets->configured(IntegrationDefinitions::CUSTOM_SMTP, 'password'),
            default => true,
        };
    }

    public function isTransactionalOnlyHost(): bool
    {
        return in_array(strtolower((string) $this->secrets->get(IntegrationDefinitions::CUSTOM_SMTP, 'host')), self::TRANSACTIONAL_ONLY_HOSTS, true);
    }

    /**
     * Cloudron's mail addon only relays mail From its own address, so
     * newsletters on Cloudron SMTP keep that From and reply to the newsletter.
     */
    public function newsletterFromIsForced(): bool
    {
        return (bool) config('mail.cloudron_forces_from', false) && $this->effectiveNewsletterTransport() === self::CLOUDRON_SMTP;
    }

    /**
     * Apply the Cloudron From rule to a newsletter sender identity.
     *
     * @param  array{address: string, name: string|null, reply_to: string|null}  $identity
     * @return array{address: string, name: string|null, reply_to: string|null}
     */
    public function newsletterIdentity(array $identity): array
    {
        if (! $this->newsletterFromIsForced() || blank(config('mail.from.address'))) {
            return $identity;
        }

        return [
            'address' => (string) config('mail.from.address'),
            'name' => $identity['name'],
            'reply_to' => $identity['reply_to'] ?? $identity['address'],
        ];
    }

    /**
     * The environment's own default mailer (Cloudron SMTP on Cloudron),
     * remembered before any setting replaces mail.default.
     */
    public function environmentMailer(): string
    {
        $remembered = config('mail.environment_default');
        if (is_string($remembered) && $remembered !== '') {
            return $remembered;
        }

        $default = (string) config('mail.default', 'smtp');
        config(['mail.environment_default' => $default]);

        return $default;
    }

    private function configureCustomSmtp(): string
    {
        $value = fn (string $key) => $this->secrets->get(IntegrationDefinitions::CUSTOM_SMTP, $key);
        $scheme = $value('scheme') === 'smtps' ? 'smtps' : 'smtp';

        config(['mail.mailers.custom-smtp' => [
            ...(array) config('mail.mailers.custom-smtp', []),
            'transport' => 'smtp',
            'scheme' => $scheme,
            'host' => $value('host'),
            'port' => (int) ($value('port') ?: ($scheme === 'smtps' ? 465 : 587)),
            'username' => $value('username'),
            'password' => $value('password'),
        ]]);

        return 'custom-smtp';
    }

    private function setting(string $key, string $fallback): string
    {
        try {
            return (string) $this->settings->get($key);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function isStored(string $key): bool
    {
        try {
            return $this->settings->isStored($key);
        } catch (Throwable) {
            return false;
        }
    }
}
