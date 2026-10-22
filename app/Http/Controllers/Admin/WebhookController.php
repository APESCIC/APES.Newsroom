<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;
use App\Services\Webhooks\OutboundWebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WebhookController extends Controller
{
    public const SECRET_FLASH = 'webhook_secret_revealed';

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', WebhookEndpoint::class);

        return Inertia::render('Admin/Webhooks/Index', [
            'endpoints' => WebhookEndpoint::query()
                ->latest()
                ->get()
                ->map(fn (WebhookEndpoint $endpoint) => [
                    'id' => $endpoint->id,
                    'name' => $endpoint->name,
                    'url' => $endpoint->url,
                    'events' => $endpoint->events,
                    'is_active' => $endpoint->is_active,
                    'secret_hint' => $endpoint->secretHint(),
                ]),
            'availableEvents' => OutboundWebhookDispatcher::supportedEvents(),
            'revealedSecret' => session(self::SECRET_FLASH),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', WebhookEndpoint::class);

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

        return redirect()->route('admin.webhooks.index')->with(self::SECRET_FLASH, $this->reveal($webhook));
    }

    public function update(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        Gate::authorize('update', $webhook);

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

            return redirect()->route('admin.webhooks.index')->with(self::SECRET_FLASH, $this->reveal($webhook));
        }

        return redirect()->route('admin.webhooks.index');
    }

    public function destroy(Request $request, WebhookEndpoint $webhook): RedirectResponse
    {
        Gate::authorize('delete', $webhook);

        $this->record($request, 'webhook.deleted', $webhook);

        $webhook->delete();

        return redirect()->route('admin.webhooks.index');
    }

    /**
     * The full secret is shown once, on the page load right after it is set.
     *
     * @return array{id: int, name: string, secret: string}
     */
    private function reveal(WebhookEndpoint $webhook): array
    {
        return ['id' => $webhook->id, 'name' => $webhook->name, 'secret' => (string) $webhook->secret];
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
