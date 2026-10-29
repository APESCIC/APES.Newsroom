<?php

namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Models\CampaignRecipient;
use App\Models\MailEvent;
use App\Services\Mail\MailEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * The Newsroom's own open pixel and click links, used only when the reader
 * allowed tracking and the send did not go through Mailgun tracking.
 */
class CampaignTrackingController extends Controller
{
    public function open(Request $request, CampaignRecipient $recipient, MailEventRecorder $recorder): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $recorder->record($this->event($recipient, MailEvent::OPENED, 'newsroom:open:'.$recipient->id));

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, CampaignRecipient $recipient, MailEventRecorder $recorder): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $target = (string) $request->query('u', '/');

        if (! str_starts_with($target, 'http://') && ! str_starts_with($target, 'https://')) {
            $target = url('/');
        }

        $recorder->record([
            ...$this->event($recipient, MailEvent::CLICKED, 'newsroom:click:'.$recipient->id.':'.hash('sha256', $target)),
            'url' => mb_substr($target, 0, 2000),
        ]);

        return redirect()->away($target);
    }

    public static function openUrl(CampaignRecipient $recipient): string
    {
        return URL::temporarySignedRoute(
            'mailing.track.open',
            now()->addDays(30),
            ['recipient' => $recipient->id],
        );
    }

    public static function clickUrl(CampaignRecipient $recipient, string $target): string
    {
        return URL::temporarySignedRoute(
            'mailing.track.click',
            now()->addDays(30),
            ['recipient' => $recipient->id, 'u' => $target],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function event(CampaignRecipient $recipient, string $type, string $eventId): array
    {
        return [
            'event_id' => $eventId,
            'source' => MailEvent::SOURCE_NEWSROOM,
            'type' => $type,
            'recipient' => strtolower($recipient->email),
            'campaign_recipient_id' => $recipient->id,
            'provider_message_id' => $recipient->provider_message_id,
            'occurred_at' => now(),
        ];
    }
}
