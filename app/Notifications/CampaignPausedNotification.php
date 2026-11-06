<?php

namespace App\Notifications;

use App\Services\EmailTemplates\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to admins once when a newsletter send pauses because its mail
 * transport failed. The reason is the transport's own error text.
 */
class CampaignPausedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $campaignId,
        public readonly string $title,
        public readonly string $reason,
        public readonly int $remaining,
        public readonly ?int $postId,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $actionLabel = $this->postId !== null ? 'Open the campaign' : 'Review mail sending';
        $actionUrl = $this->postId !== null
            ? route('staff.posts.campaign.preview', $this->postId)
            : route('admin.settings.mail-sending.index');

        return app(TemplatedMail::class)->build(
            key: 'campaign_paused',
            placeholders: [
                'title' => $this->title,
                'reason' => $this->reason,
                'remaining' => $this->remaining,
                'campaign_url' => $actionUrl,
            ],
            actionLabel: $actionLabel,
            actionUrl: $actionUrl,
            fallback: function () use ($actionLabel, $actionUrl): MailMessage {
                $message = (new MailMessage)
                    ->subject("APES Newsroom: newsletter send paused ({$this->title})")
                    ->line("Sending \"{$this->title}\" has paused because the newsletter mail transport failed. {$this->remaining} recipients are still waiting.")
                    ->line($this->reason)
                    ->line('Nothing switches to another transport. Fix the problem under Admin → Settings → Mail sending, then resume the send from the campaign page.');

                return $message->action($actionLabel, $actionUrl);
            },
        );
    }
}
