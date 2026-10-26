<?php

namespace App\Http\Controllers\Admin;

use App\Enums\IntegrationCheckStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IntegrationCheckResult;
use App\Models\IntegrationSecret;
use App\Services\Audit\AuditLogger;
use App\Services\Health\HealthCheckRunner;
use App\Services\Health\IntegrationAlerts;
use App\Services\Integrations\IntegrationSecrets;
use App\Support\Integrations\IntegrationDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Settings → Integrations. Key values are write-only: the page only
 * ever receives whether a key is set, where it comes from and its last four
 * characters (full value for non-sensitive settings such as a domain).
 */
class IntegrationController extends Controller
{
    public const AUDIT_ACTIONS = [
        'integration_key.set',
        'integration_key.replaced',
        'integration_key.removed',
    ];

    private const HISTORY_LIMIT = 10;

    public function __construct(
        private readonly IntegrationSecrets $secrets,
        private readonly HealthCheckRunner $runner,
        private readonly IntegrationAlerts $alerts,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('access-admin-area');

        $stored = IntegrationSecret::query()->with('setBy:id,name')->get()->keyBy(fn (IntegrationSecret $secret) => $secret->provider.'.'.$secret->key);
        $rotations = $this->rotations();
        $providers = [];

        foreach (IntegrationDefinitions::providers() as $provider => $definition) {
            $latest = IntegrationCheckResult::latestFor($provider);

            $providers[] = [
                'provider' => $provider,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'managed' => $definition['managed'],
                'keys' => collect($definition['keys'])
                    ->map(fn (array $key, string $name) => $this->keyStatus($provider, $name, $key, $stored->get($provider.'.'.$name)))
                    ->values()
                    ->all(),
                'configured' => $this->runner->check($provider)->configured(),
                'latest' => $latest ? $this->resultPayload($latest) : null,
                'history' => IntegrationCheckResult::query()
                    ->with('user:id,name')
                    ->where('provider', $provider)
                    ->latest('checked_at')
                    ->latest('id')
                    ->limit(self::HISTORY_LIMIT)
                    ->get()
                    ->map(fn (IntegrationCheckResult $result) => $this->resultPayload($result))
                    ->all(),
                'rotations' => $rotations->get($provider, collect())->take(self::HISTORY_LIMIT)->values()->all(),
            ];
        }

        return Inertia::render('Admin/Settings/Integrations/Index', [
            'providers' => $providers,
            'canManage' => $request->user()->can('access-super-admin-area'),
            'failedCheck' => session('integration_failed_check'),
        ]);
    }

    public function update(Request $request, string $provider, string $key): RedirectResponse
    {
        Gate::authorize('access-super-admin-area');
        abort_unless(IntegrationDefinitions::hasKey($provider, $key), 404);

        $label = IntegrationDefinitions::phrase($provider, $key);
        $validated = $request->validate([
            'secret_value' => ['required', ...IntegrationDefinitions::key($provider, $key)['rules']],
            'force' => ['sometimes', 'boolean'],
        ], [], ['secret_value' => $label]);

        $value = trim((string) $validated['secret_value']);
        $check = $this->runner->check($provider);
        $result = null;

        if ($check->configured([$key => $value])) {
            $result = $this->runner->run($provider, IntegrationCheckResult::TRIGGER_PRE_SAVE, $request->user(), [$key => $value]);
            $this->record($request, 'integration_key.tested', $provider, $key, IntegrationSecret::lastFourOf($value), [
                'trigger' => IntegrationCheckResult::TRIGGER_PRE_SAVE,
                'status' => $result->status->value,
            ]);

            if ($result->status === IntegrationCheckStatus::Fail && ! $request->boolean('force')) {
                return back()
                    ->with('integration_failed_check', ['provider' => $provider, 'key' => $key])
                    ->withErrors([
                        'secret_value' => "Connection test failed: {$result->message} The {$label} was not saved. Fix the value, or choose Save anyway.",
                    ]);
            }
        }

        $saved = $this->secrets->set($provider, $key, $value, $request->user());

        $this->record($request, $saved['replaced'] ? 'integration_key.replaced' : 'integration_key.set', $provider, $key, $saved['secret']->last_four, [
            'check_status' => $result?->status->value,
            'forced' => $result?->status === IntegrationCheckStatus::Fail,
        ]);

        return redirect()
            ->route('admin.settings.integrations.index')
            ->with('status', $label.($saved['replaced'] ? ' replaced.' : ' saved.'));
    }

    public function destroy(Request $request, string $provider, string $key): RedirectResponse
    {
        Gate::authorize('access-super-admin-area');
        abort_unless(IntegrationDefinitions::hasKey($provider, $key), 404);

        $removed = $this->secrets->remove($provider, $key);
        $label = IntegrationDefinitions::phrase($provider, $key);

        if ($removed === null) {
            return redirect()->route('admin.settings.integrations.index')->with('status', $label.' has no stored value to remove.');
        }

        $this->record($request, 'integration_key.removed', $provider, $key, $removed->last_four);

        return redirect()
            ->route('admin.settings.integrations.index')
            ->with('status', $label.' removed. The environment value, if any, is used instead.');
    }

    public function test(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('access-admin-area');
        abort_unless(in_array($provider, IntegrationDefinitions::providerNames(), true), 404);

        $previous = IntegrationCheckResult::latestFor($provider);
        $result = $this->runner->run($provider, IntegrationCheckResult::TRIGGER_MANUAL, $request->user());
        $this->alerts->afterRun($previous, $result);

        $this->audit->record($request->user(), 'integration_key.tested', null, [
            'provider' => $provider,
            'trigger' => IntegrationCheckResult::TRIGGER_MANUAL,
            'status' => $result->status->value,
        ], $request);

        return redirect()
            ->route('admin.settings.integrations.index')
            ->with('status', IntegrationDefinitions::provider($provider)['label'].': '.$result->status->label().'. '.$result->message);
    }

    /**
     * @param  array{label: string, help: string, config: string, sensitive: bool, rules: list<string>}  $definition
     * @return array<string, mixed>
     */
    private function keyStatus(string $provider, string $name, array $definition, ?IntegrationSecret $stored): array
    {
        $source = $this->secrets->source($provider, $name);
        $value = $source === null ? null : $this->secrets->get($provider, $name);

        return [
            'key' => $name,
            'label' => $definition['label'],
            'help' => $definition['help'],
            'sensitive' => $definition['sensitive'],
            'source' => $source,
            'last_four' => $value !== null && $definition['sensitive'] ? IntegrationSecret::lastFourOf($value) : null,
            'value' => $value !== null && ! $definition['sensitive'] ? $value : null,
            'set_at' => $stored?->set_at?->toIso8601String(),
            'set_by' => $stored?->setBy?->name,
            'rotated_at' => $stored?->rotated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resultPayload(IntegrationCheckResult $result): array
    {
        return [
            'id' => $result->id,
            'status' => $result->status->value,
            'message' => $result->message,
            'duration_ms' => $result->duration_ms,
            'trigger' => $result->trigger,
            'checked_at' => $result->checked_at->toIso8601String(),
            'user' => $result->user?->name,
        ];
    }

    /**
     * Rotation history per provider, read from the audit log.
     *
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    private function rotations(): Collection
    {
        return AuditLog::query()
            ->with('actor:id,name')
            ->whereIn('action', self::AUDIT_ACTIONS)
            ->latest('id')
            ->limit(200)
            ->get()
            ->filter(fn (AuditLog $log) => IntegrationDefinitions::hasKey((string) ($log->payload['provider'] ?? ''), (string) ($log->payload['key'] ?? '')))
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'provider' => (string) $log->payload['provider'],
                'action' => str_replace('integration_key.', '', $log->action),
                'key_label' => IntegrationDefinitions::key((string) $log->payload['provider'], (string) $log->payload['key'])['label'],
                'last_four' => $log->payload['last_four'] ?? null,
                'actor' => $log->actor?->name,
                'at' => $log->created_at?->toIso8601String(),
            ])
            ->groupBy('provider');
    }

    /**
     * Audit entries carry the last four characters only, never a value.
     *
     * @param  array<string, mixed>  $extra
     */
    private function record(Request $request, string $action, string $provider, string $key, string $lastFour, array $extra = []): void
    {
        $this->audit->record($request->user(), $action, null, [
            'provider' => $provider,
            'key' => $key,
            'last_four' => $lastFour,
            ...$extra,
        ], $request);
    }
}
