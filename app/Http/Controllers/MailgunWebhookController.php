<?php

namespace App\Http\Controllers;

use App\Services\Integrations\IntegrationSecrets;
use App\Services\Mail\MailEventRecorder;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Receives Mailgun webhooks (delivered, failed, opened, clicked, complained,
 * unsubscribed). Each request must carry an HMAC-SHA256 signature of its
 * timestamp and token made with the account's webhook signing key, be
 * recent, and not reuse a token.
 */
class MailgunWebhookController extends Controller
{
    public const MAX_AGE_SECONDS = 300;

    public function __invoke(Request $request, IntegrationSecrets $secrets, MailEventRecorder $recorder): Response
    {
        $key = $secrets->get(IntegrationDefinitions::MAILGUN, 'webhook_signing_key');
        if (blank($key)) {
            return response('Webhook signing key not configured.', 503);
        }

        $timestamp = (string) $request->input('signature.timestamp', '');
        $token = (string) $request->input('signature.token', '');
        $signature = (string) $request->input('signature.signature', '');

        if ($timestamp === '' || $token === '' || $signature === ''
            || ! hash_equals(hash_hmac('sha256', $timestamp.$token, (string) $key), $signature)) {
            return response('Invalid signature.', 403);
        }

        if (! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            return response('Stale signature.', 403);
        }

        if (! Cache::add('mailgun-webhook-token:'.hash('sha256', $token), true, self::MAX_AGE_SECONDS * 2)) {
            return response('Already processed.', 200);
        }

        $data = $request->input('event-data');
        if (is_array($data)) {
            $recorder->recordMailgun($data);
        }

        return response('ok', 200);
    }
}
