<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
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

        WebhookEndpoint::query()->create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'secret' => WebhookEndpoint::generateSecret(),
            'events' => array_values($validated['events']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

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

        if (! empty($validated['rotate_secret'])) {
            $webhook->secret = WebhookEndpoint::generateSecret();
        }

        $webhook->save();

        return redirect()->route('staff.webhooks.index');
    }

    public function destroy(WebhookEndpoint $webhook): RedirectResponse
    {
        $webhook->delete();

        return redirect()->route('staff.webhooks.index');
    }
}
