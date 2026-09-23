<?php

namespace App\Http\Controllers\Mailing;

use App\Http\Controllers\Controller;
use App\Models\CampaignRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

class CampaignTrackingController extends Controller
{
    public function open(Request $request, CampaignRecipient $recipient): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        if ($recipient->opened_at === null) {
            $recipient->forceFill(['opened_at' => now()])->save();
        }

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, CampaignRecipient $recipient): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $target = (string) $request->query('u', '/');

        if (! str_starts_with($target, 'http://') && ! str_starts_with($target, 'https://')) {
            $target = url('/');
        }

        if ($recipient->clicked_at === null) {
            $recipient->forceFill([
                'clicked_at' => now(),
                'opened_at' => $recipient->opened_at ?? now(),
            ])->save();
        }

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
}
