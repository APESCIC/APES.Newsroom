<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('Staff/Webhooks/Index', [
            'endpoints' => WebhookEndpoint::query()
                ->latest()
                ->get()
                ->map(fn (WebhookEndpoint $endpoint) => [
                    'id' => $endpoint->id,
                    'name' => $endpoint->name,
                    'url' => $endpoint->url,
                    'events' => $endpoint->events,
                    'is_active' => $endpoint->is_active,
                    'secret_hint' => 'whsec_…'.substr($endpoint->secret, -4),
                ]),
            'availableEvents' => OutboundWebhookDispatcher::supportedEvents(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(OutboundWebhookDispatcher::supportedEvents())],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $webhook = WebhookEndpoint::query()->create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'secret' => WebhookEndpoint::generateSecret(),
            'events' => array_values($validated['events']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $this->record($request, 'webhook.created', $webhook);

        return redirect()->route('staff.webhooks.index');
    }

    public function update(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(OutboundWebhookDispatcher::supportedEvents())],
            'is_active' => ['required', 'boolean'],
            'rotate_secret' => ['sometimes', 'boolean'],
        ]);

        $webhook->fill([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'events' => array_values($validated['events']),
            'is_active' => (bool) $validated['is_active'],
        ]);

        $rotated = ! empty($validated['rotate_secret']);

        if ($rotated) {
            $webhook->secret = WebhookEndpoint::generateSecret();
        }

        $webhook->save();

        $this->record($request, 'webhook.updated', $webhook);

        if ($rotated) {
            $this->record($request, 'webhook.secret_rotated', $webhook);
        }

        return redirect()->route('staff.webhooks.index');
    }

    public function destroy(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        $this->record($request, 'webhook.deleted', $webhook);

        $webhook->delete();

        return redirect()->route('staff.webhooks.index');
    }

    /**
     * Only the host is kept: query strings on webhook URLs can carry credentials.
     */
    private function record(Request $request, string $action, WebhookEndpoint $webhook): void
    {
        $this->audit->record($request->user(), $action, $webhook, [
            'name' => $webhook->name,
            'host' => parse_url($webhook->url, PHP_URL_HOST),
            'events' => $webhook->events,
            'is_active' => $webhook->is_active,
        ], $request);
    }
}
