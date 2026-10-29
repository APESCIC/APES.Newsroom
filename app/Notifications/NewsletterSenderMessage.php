<?php

namespace App\Notifications;

use App\Models\Newsletter;
use App\Services\Mail\MailTransports;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\SenderDomains;
use Illuminate\Notifications\Messages\MailMessage;

class NewsletterSenderMessage
{
    public static function apply(MailMessage $message, ?Newsletter $newsletter): MailMessage
    {
        $identity = app(CampaignSender::class)->verifiedIdentity($newsletter);
        if (! $identity) {
            return $message;
        }
        $identity = app(MailTransports::class)->newsletterIdentity($identity);

        $mailer = SenderDomains::newsletterMailer();
        if ($mailer !== null) {
            $message->mailer($mailer);
        }

        $message->from($identity['address'], $identity['name']);
        if (filled($identity['reply_to'])) {
            $message->replyTo($identity['reply_to']);
        }

        return $message;
    }
}
