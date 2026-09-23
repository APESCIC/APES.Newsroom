<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
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
    ) {}

    public function envelope(): Envelope
    {
        $title = $this->snapshot['title'] ?? 'APES Newsroom';
        $subject = $this->isTest
            ? '[TEST] '.$title
            : $title;

        return new Envelope(subject: $subject);
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
            text: [
                'List-Unsubscribe' => '<'.$this->listUnsubscribeUrl.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }
}
