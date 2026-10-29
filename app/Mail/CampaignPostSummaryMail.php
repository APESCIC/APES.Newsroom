<?php

namespace App\Mail;

use App\Services\Mail\MailgunApiTransport;
use App\Services\Mail\MailTransports;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class CampaignPostSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public readonly array $snapshot,
        public readonly string $unsubscribeUrl,
        public readonly string $preferencesUrl,
        public readonly string $listUnsubscribeUrl,
        public readonly bool $isTest = false,
        public readonly ?string $openPixelUrl = null,
        public readonly ?string $trackedReadMoreUrl = null,
        public readonly bool $providerTrackOpens = false,
        public readonly bool $providerTrackClicks = false,
        public readonly ?int $campaignRecipientId = null,
    ) {}

    public function envelope(): Envelope
    {
        $title = $this->snapshot['title'] ?? 'APES Newsroom';
        $subject = $this->isTest
            ? '[TEST] '.$title
            : $title;

        $sender = is_array($this->snapshot['sender'] ?? null) ? $this->snapshot['sender'] : null;
        if (! $sender || blank($sender['address'] ?? null)) {
            return new Envelope(subject: $subject);
        }
        $sender = app(MailTransports::class)->newsletterIdentity([
            'address' => (string) $sender['address'],
            'name' => filled($sender['name'] ?? null) ? (string) $sender['name'] : null,
            'reply_to' => filled($sender['reply_to'] ?? null) ? (string) $sender['reply_to'] : null,
        ]);

        return new Envelope(
            from: new Address($sender['address'], $sender['name']),
            replyTo: $sender['reply_to'] !== null ? [new Address($sender['reply_to'])] : [],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.campaign-post-summary',
            with: [
                'snapshot' => $this->snapshot,
                'unsubscribeUrl' => $this->unsubscribeUrl,
                'preferencesUrl' => $this->preferencesUrl,
                'isTest' => $this->isTest,
                'openPixelUrl' => $this->openPixelUrl,
                'trackedReadMoreUrl' => $this->trackedReadMoreUrl ?? ($this->snapshot['read_more_url'] ?? '#'),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: array_filter([
                'List-Unsubscribe' => '<'.$this->listUnsubscribeUrl.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                MailgunApiTransport::TRACK_OPENS_HEADER => $this->providerTrackOpens ? 'yes' : 'no',
                MailgunApiTransport::TRACK_CLICKS_HEADER => $this->providerTrackClicks ? 'yes' : 'no',
                'X-Mailgun-Variables' => $this->campaignRecipientId !== null ? json_encode(['campaign_recipient_id' => $this->campaignRecipientId]) : null,
            ]),
        );
    }
}
