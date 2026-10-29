<?php

namespace App\Services\Mail;

use App\Enums\SenderAddressPurpose;
use App\Enums\SendingDomainStatus;
use App\Mail\TransportTestMail;
use App\Models\EmailSenderAddress;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\IntegrationSecrets;
use App\Services\Mailing\DefaultReplyTo;
use App\Support\Integrations\IntegrationDefinitions;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Http\Request;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tests the transport chosen for system mail or newsletters: a connection
 * and sign-in check that sends nothing, or a real test email to the admin
 * (plus one optional address). Results are stored and audited.
 */
class TransportTester
{
    public const PURPOSES = ['system' => 'System mail', 'newsletters' => 'Newsletters'];

    public const MODES = ['connection', 'send'];

    public function __construct(
        private readonly MailTransports $transports,
        private readonly SmtpHandshake $handshake,
        private readonly MailgunDomainService $mailgun,
        private readonly IntegrationSecrets $secrets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{ok: bool, purpose: string, transport: string, transport_label: string, mode: string, message: string, recipients: list<string>, message_id: string|null, error: string|null, tested_at: string}
     */
    public function run(string $purpose, string $mode, User $actor, ?string $extraRecipient = null, ?Request $request = null): array
    {
        $transport = $purpose === 'system' ? $this->transports->systemTransport() : $this->transports->newsletterTransport();
        $label = SettingDefinitions::transportOptions()[$transport] ?? $transport;
        $recipients = [];
        $messageId = null;

        if ($mode === 'connection') {
            [$error, $message] = $this->connection($transport);
        } else {
            $recipients = array_values(array_unique(array_filter([strtolower((string) $actor->email), $extraRecipient !== null ? strtolower($extraRecipient) : null])));
            [$messageId, $error] = $this->send($transport, $purpose, $label, $recipients, $actor);
            $message = $error === null
                ? 'Sent to '.implode(', ', $recipients).' through '.$label.'.'
                : 'Not sent through '.$label.'.';
        }

        $result = [
            'ok' => $error === null,
            'purpose' => $purpose,
            'transport' => $transport,
            'transport_label' => $label,
            'mode' => $mode,
            'message' => $message,
            'recipients' => $recipients,
            'message_id' => $messageId,
            'error' => $error,
            'tested_at' => now()->toIso8601String(),
        ];

        Setting::query()->updateOrCreate(['key' => self::resultKey($purpose)], ['value' => $result]);

        $this->audit->record($actor, 'mail_sending.tested', null, [
            'purpose' => $purpose,
            'transport' => $transport,
            'mode' => $mode,
            'outcome' => $result['ok'] ? 'passed' : 'failed',
            'recipients' => $recipients,
            'error' => $error,
        ], $request);

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function lastResult(string $purpose): ?array
    {
        $value = Setting::query()->where('key', self::resultKey($purpose))->value('value');

        return is_array($value) ? $value : null;
    }

    private static function resultKey(string $purpose): string
    {
        return 'mail.last_test.'.$purpose;
    }

    /**
     * @return array{0: string|null, 1: string} Error and message.
     */
    private function connection(string $transport): array
    {
        if ($transport === MailTransports::MAILGUN_API) {
            $error = $this->mailgun->ping();

            return [$error, $error === null ? 'The Mailgun API accepted the key.' : 'The Mailgun API check failed.'];
        }

        if ($transport === MailTransports::CUSTOM_SMTP) {
            $value = fn (string $key) => $this->secrets->get(IntegrationDefinitions::CUSTOM_SMTP, $key);
            $error = $this->handshake->check([
                'host' => $value('host'),
                'port' => $value('port'),
                'scheme' => $value('scheme'),
                'username' => $value('username'),
                'password' => $value('password'),
            ]);

            return [$error, $error === null ? 'Signed in to the SMTP server. Nothing was sent.' : 'The SMTP server refused the connection or sign-in.'];
        }

        $name = $this->transports->environmentMailer();
        $config = (array) config("mail.mailers.{$name}", []);
        if (($config['transport'] ?? null) !== 'smtp') {
            return [null, "The environment mailer ({$name}) has no SMTP server to check."];
        }

        $error = $this->handshake->check([
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'scheme' => $config['scheme'] ?? null,
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
        ]);

        return [$error, $error === null ? 'Connected to Cloudron SMTP. Nothing was sent.' : 'Cloudron SMTP refused the connection or sign-in.'];
    }

    /**
     * @param  list<string>  $recipients
     * @return array{0: string|null, 1: string|null} Message ID and error.
     */
    private function send(string $transport, string $purpose, string $label, array $recipients, User $actor): array
    {
        $unavailable = $this->transports->unavailableReason($transport, $purpose === 'newsletters');
        if ($unavailable !== null) {
            return [null, $unavailable];
        }

        $identity = $purpose === 'newsletters'
            ? $this->transports->newsletterIdentity($this->newsletterIdentity())
            : ['address' => (string) config('mail.from.address'), 'name' => config('mail.from.name'), 'reply_to' => null];

        try {
            $pending = Mail::mailer($this->transports->mailerFor($transport))->to($recipients[0]);
            if (count($recipients) > 1) {
                $pending->bcc(array_slice($recipients, 1));
            }
            $sent = $pending->send(new TransportTestMail(
                $identity['address'],
                filled($identity['name']) ? (string) $identity['name'] : null,
                $label,
                self::PURPOSES[$purpose],
                $actor->name ?? $actor->email,
                $identity['reply_to'],
            ));
        } catch (Throwable $exception) {
            return [null, mb_substr($exception->getMessage(), 0, 500)];
        }

        return [$sent instanceof SentMessage ? trim((string) $sent->getMessageId(), '<> ') : null, null];
    }

    /**
     * A newsletter From address on a verified domain, else the site From.
     *
     * @return array{address: string, name: string|null, reply_to: string|null}
     */
    private function newsletterIdentity(): array
    {
        $address = EmailSenderAddress::query()
            ->enabled()
            ->where('purpose', SenderAddressPurpose::News->value)
            ->whereHas('domain', fn ($query) => $query->where('status', SendingDomainStatus::Verified->value))
            ->orderBy('address')
            ->value('address');

        return [
            'address' => $address ?? (string) config('mail.from.address'),
            'name' => 'APES Newsroom',
            'reply_to' => DefaultReplyTo::usable(),
        ];
    }
}
