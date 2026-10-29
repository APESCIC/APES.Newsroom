<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailSendingDomain;
use App\Models\IntegrationSecret;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\IntegrationSecrets;
use App\Services\Mail\MailgunWebhookManager;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Webhooks and open/click tracking for each sending domain's Mailgun domain
 * (Admin → Settings → Email sending domains). Admins can re-read the state;
 * only super admins change it.
 */
class MailgunDomainWebhooksController extends Controller
{
    /** Remind super admins to rotate a management key older than this. */
    public const ROTATE_AFTER_DAYS = 90;

    public function __construct(
        private readonly MailgunWebhookManager $webhooks,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{configured: bool, signing_key_configured: bool, uses_sending_key: bool, rotate_due: bool, can_manage: bool, callback_url: string}
     */
    public static function managementStatus(Request $request): array
    {
        $manager = app(MailgunWebhookManager::class);
        $secret = IntegrationSecret::query()->where(['provider' => IntegrationDefinitions::MAILGUN, 'key' => 'management_key'])->first();
        $setAt = $secret?->rotated_at ?? $secret?->set_at;

        return [
            'configured' => $manager->isConfigured(),
            'signing_key_configured' => app(IntegrationSecrets::class)->configured(IntegrationDefinitions::MAILGUN, 'webhook_signing_key'),
            'uses_sending_key' => $manager->usesSendingKey(),
            'rotate_due' => $setAt !== null && $setAt->lt(now()->subDays(self::ROTATE_AFTER_DAYS)),
            'can_manage' => $request->user()->can('access-super-admin-area'),
            'callback_url' => $manager->callbackUrl(),
        ];
    }

    public function refresh(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        Gate::authorize('access-admin-area');

        return $this->run(fn () => $this->webhooks->refresh($emailSendingDomain)['error']
            ?? "Read the webhooks and tracking for {$emailSendingDomain->domain} from Mailgun.");
    }

    public function register(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        return $this->change($request, $emailSendingDomain, 'register', fn () => $this->webhooks->register($emailSendingDomain));
    }

    public function repair(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        return $this->change($request, $emailSendingDomain, 'repair', fn () => $this->webhooks->repair($emailSendingDomain));
    }

    public function remove(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        return $this->change($request, $emailSendingDomain, 'remove', fn () => $this->webhooks->remove($emailSendingDomain));
    }

    public function tracking(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        Gate::authorize('access-super-admin-area');
        $validated = $request->validate([
            'track_opens' => ['required', 'boolean'],
            'track_clicks' => ['required', 'boolean'],
        ]);

        $before = ['track_opens' => $emailSendingDomain->track_opens, 'track_clicks' => $emailSendingDomain->track_clicks];
        $after = ['track_opens' => (bool) $validated['track_opens'], 'track_clicks' => (bool) $validated['track_clicks']];

        try {
            if ($this->webhooks->isConfigured()) {
                $this->webhooks->setTracking($emailSendingDomain, 'open', $after['track_opens']);
                $this->webhooks->setTracking($emailSendingDomain, 'click', $after['track_clicks']);
            }
        } catch (RuntimeException $exception) {
            return back()->withErrors(['webhooks' => $exception->getMessage()]);
        }

        $emailSendingDomain->forceFill($after)->save();
        if ($after !== $before) {
            $this->audit->record($request->user(), 'email_sending_domain.tracking_changed', $emailSendingDomain, [
                'domain' => $emailSendingDomain->domain,
                'from' => $before,
                'to' => $after,
                'applied_in_mailgun' => $this->webhooks->isConfigured(),
            ], $request);
        }

        if ($this->webhooks->isConfigured()) {
            $this->webhooks->refresh($emailSendingDomain);
        }

        return back()->with('status', $this->webhooks->isConfigured()
            ? "Saved tracking for {$emailSendingDomain->domain} and applied it in Mailgun."
            : "Saved tracking for {$emailSendingDomain->domain}. Set the webhook management key to apply it in Mailgun too.");
    }

    /**
     * @param  callable(): list<string>  $action
     */
    private function change(Request $request, EmailSendingDomain $domain, string $verb, callable $action): RedirectResponse
    {
        Gate::authorize('access-super-admin-area');

        return $this->run(function () use ($request, $domain, $verb, $action) {
            $events = $action();
            $this->audit->record($request->user(), "email_sending_domain.webhooks_{$verb}", $domain, [
                'domain' => $domain->domain,
                'events' => $events,
                'url' => $this->webhooks->callbackUrl(),
            ], $request);

            return $events === []
                ? "Webhooks on {$domain->domain} were already up to date."
                : ucfirst($verb === 'remove' ? 'removed' : ($verb === 'repair' ? 'repaired' : 'registered'))." webhooks on {$domain->domain}: ".implode(', ', $events).'.';
        });
    }

    /**
     * @param  callable(): string  $action
     */
    private function run(callable $action): RedirectResponse
    {
        try {
            return back()->with('status', $action());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['webhooks' => $exception->getMessage()]);
        }
    }
}
