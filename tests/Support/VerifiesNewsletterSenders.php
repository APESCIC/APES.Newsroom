<?php

namespace Tests\Support;

use App\Models\Newsletter;
use App\Services\Mailing\CampaignSender;

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
        $newsletter->forceFill(['sender_verification' => [
            'status' => CampaignSender::VERIFIED,
            'sender_email' => $newsletter->sender_email,
            'checked_at' => now()->toIso8601String(),
            'message' => 'Verified in test.',
        ]])->save();
    }
}
