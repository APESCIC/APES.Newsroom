<?php

namespace App\Services\Mail;

use App\Services\Integrations\IntegrationSecrets;
use App\Services\Mailing\SenderDomains;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;

/**
 * Sends through the Mailgun HTTP API (POST /v3/{domain}/messages.mime), using
 * the Mailgun domain of the From address's approved sending domain. A From
 * domain that is not approved and enabled is refused.
 */
class MailgunApiTransport extends AbstractTransport
{
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
            throw new TransportException("The sender domain of {$from} is not an approved, enabled sending domain.");
        }

        $apiKey = $this->secrets->get(IntegrationDefinitions::MAILGUN, 'api_key');
        if (blank($apiKey)) {
            throw new TransportException('The Mailgun API key is not configured under Integrations.');
        }

        $endpoint = trim($this->secrets->get(IntegrationDefinitions::MAILGUN, 'endpoint') ?? 'api.eu.mailgun.net', '/');
        $recipients = implode(',', array_map(fn (Address $address) => $address->getAddress(), $message->getEnvelope()->getRecipients()));

        try {
            $response = Http::withBasicAuth('api', (string) $apiKey)
                ->acceptJson()
                ->timeout(20)
                ->attach('message', $message->toString(), 'message.mime')
                ->post('https://'.$endpoint.'/v3/'.rawurlencode($mailgunDomain).'/messages.mime', ['to' => $recipients]);
        } catch (ConnectionException) {
            throw new TransportException('Could not reach the Mailgun API.');
        }

        if (! $response->successful()) {
            $reason = $response->json('message') ?? $response->body();

            throw new TransportException('Mailgun refused the message (HTTP '.$response->status().'): '.trim((string) $reason));
        }

        $id = trim((string) $response->json('id'), '<> ');
        if ($id !== '') {
            $message->setMessageId($id);
        }
    }

    public function __toString(): string
    {
        return 'mailgun+api://'.($this->secrets->get(IntegrationDefinitions::MAILGUN, 'endpoint') ?? 'api.eu.mailgun.net');
    }
}
