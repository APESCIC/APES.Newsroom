<?php

namespace Tests\Support;

use App\Enums\SendingDomainStatus;
use App\Models\EmailSendingDomain;
use App\Models\Newsletter;
use App\Services\Mailing\CampaignSender;
use App\Services\Mailing\SenderDomains;

trait VerifiesNewsletterSenders
{
    protected function verifyNewsletterSenders(): void
    {
        Newsletter::query()->whereNotNull('sender_email')->get()->each(
            fn (Newsletter $newsletter) => $this->markSenderVerified($newsletter),
        );
    }

    protected function markSenderVerified(Newsletter $newsletter): void
    {
        $this->markSendingDomainVerified(SenderDomains::domainOf((string) $newsletter->sender_email));

        $newsletter->forceFill(['sender_verification' => [
            'status' => CampaignSender::VERIFIED,
            'sender_email' => $newsletter->sender_email,
            'checked_at' => now()->toIso8601String(),
            'message' => 'Verified in test.',
        ]])->save();
    }

    protected function markSendingDomainVerified(string $domain): EmailSendingDomain
    {
        $record = EmailSendingDomain::query()->firstOrNew(['domain' => $domain]);
        $record->forceFill(['status' => SendingDomainStatus::Verified, 'verified_at' => now()])->save();

        return $record;
    }
}
