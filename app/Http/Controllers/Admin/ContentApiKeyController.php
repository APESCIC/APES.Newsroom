<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentApiKey;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ContentApiKeyController extends Controller
{
    public const CREATED_FLASH = 'content_api_key_created';

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ContentApiKey::class);

        return Inertia::render('Admin/ContentApiKeys/Index', [
            'keys' => ContentApiKey::query()
                ->with('creator:id,name')
                ->latest('id')
                ->get()
                ->map(fn (ContentApiKey $key) => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'prefix' => $key->prefix,
                    'created_by' => $key->creator?->name,
                    'created_at' => $key->created_at?->toIso8601String(),
                    'last_used_at' => $key->last_used_at?->toIso8601String(),
                    'revoked_at' => $key->revoked_at?->toIso8601String(),
                ])
                ->values(),
            'environmentKeyConfigured' => (string) config('newsroom.content_api.key', '') !== '',
            'created' => $request->session()->get(self::CREATED_FLASH),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', ContentApiKey::class);

        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $issued = ContentApiKey::issue($validated['name'], $request->user());

        $this->audit->record($request->user(), 'content_api_key.created', $issued['model'], [
            'name' => $issued['model']->name,
            'prefix' => $issued['model']->prefix,
        ], $request);

        return redirect()->route('admin.content-api-keys.index')->with(self::CREATED_FLASH, [
            'id' => $issued['model']->id,
            'name' => $issued['model']->name,
            'key' => $issued['key'],
        ]);
    }

    public function destroy(Request $request, ContentApiKey $contentApiKey): RedirectResponse
    {
        Gate::authorize('revoke', ContentApiKey::class);
        $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason']);

        if (! $contentApiKey->isRevoked()) {
            $contentApiKey->forceFill(['revoked_at' => now()])->save();

            $this->audit->record($request->user(), 'content_api_key.revoked', $contentApiKey, [
                'name' => $contentApiKey->name,
                'prefix' => $contentApiKey->prefix,
                'reason' => $reason,
            ], $request);
        }

        return redirect()->route('admin.content-api-keys.index')->with('status', "Key \"{$contentApiKey->name}\" revoked.");
    }
}
