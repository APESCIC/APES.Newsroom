<?php

namespace App\Services\Mail;

use App\Services\Integrations\IntegrationSecrets;
use App\Services\Mailing\SenderDomains;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;

/**
 * Sends through the Mailgun HTTP API (POST /v3/{domain}/messages.mime), using
 * the Mailgun domain of the From address's approved sending domain. A From
 * domain that is not approved and enabled is refused.
 *
 * Failures are typed: MailMessageRefused for a refusal of this one message,
 * MailTransportUnavailable for everything that stops all sending.
 */
class MailgunApiTransport extends AbstractTransport
{
    public const TRACK_OPENS_HEADER = 'X-Mailgun-Track-Opens';

    public const TRACK_CLICKS_HEADER = 'X-Mailgun-Track-Clicks';

    public function __construct(
        private readonly IntegrationSecrets $secrets,
        private readonly MailgunDomainService $mailgun,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $from = $message->getEnvelope()->getSender()->getAddress();
        $mailgunDomain = SenderDomains::mailgunDomainForSender($from, $this->mailgun);

        if ($mailgunDomain === null) {
            throw new MailTransportUnavailable("The sender domain of {$from} is not an approved, enabled sending domain.");
        }

        $apiKey = $this->secrets->get(IntegrationDefinitions::MAILGUN, 'api_key');
        if (blank($apiKey)) {
            throw new MailTransportUnavailable('The Mailgun API key is not configured under Integrations.');
        }

        $endpoint = trim($this->secrets->get(IntegrationDefinitions::MAILGUN, 'endpoint') ?? 'api.eu.mailgun.net', '/');
        $recipients = implode(',', array_map(fn (Address $address) => $address->getAddress(), $message->getEnvelope()->getRecipients()));

        try {
            $response = Http::withBasicAuth('api', (string) $apiKey)
                ->acceptJson()
                ->timeout(20)
                ->attach('message', $message->toString(), 'message.mime')
                ->post('https://'.$endpoint.'/v3/'.rawurlencode($mailgunDomain).'/messages.mime', [
                    'to' => $recipients,
                    ...$this->trackingOptions($message),
                ]);
        } catch (ConnectionException) {
            throw new MailTransportUnavailable('Could not reach the Mailgun API.', permanent: false);
        }

        if (! $response->successful()) {
            $reason = 'Mailgun refused the message (HTTP '.$response->status().'): '.trim((string) ($response->json('message') ?? $response->body()));

            throw match (true) {
                $response->status() === 429, $response->serverError() => new MailTransportUnavailable($reason, permanent: false),
                in_array($response->status(), [401, 403, 404], true) => new MailTransportUnavailable($reason),
                default => new MailMessageRefused($reason),
            };
        }

        $id = trim((string) $response->json('id'), '<> ');
        if ($id !== '') {
            $message->setMessageId($id);
        }
    }

    /**
     * Per-message tracking flags set by the mailable, passed as o:tracking-*
     * so they override the domain's tracking settings.
     *
     * @return array<string, string>
     */
    private function trackingOptions(SentMessage $message): array
    {
        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            return [];
        }

        $options = [];
        foreach (['o:tracking-opens' => self::TRACK_OPENS_HEADER, 'o:tracking-clicks' => self::TRACK_CLICKS_HEADER] as $option => $header) {
            $value = $original->getHeaders()->get($header)?->getBodyAsString();
            if ($value !== null) {
                $options[$option] = $value === 'yes' ? 'yes' : 'no';
            }
        }

        return $options;
    }

    public function __toString(): string
    {
        return 'mailgun+api://'.($this->secrets->get(IntegrationDefinitions::MAILGUN, 'endpoint') ?? 'api.eu.mailgun.net');
    }
}
