<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Request $request): Response
    {
        $this->authorizeAdmin();

        $values = $this->settings->all();
        $settings = [];

        foreach (SettingDefinitions::all() as $key => $definition) {
            $settings[] = [
                'key' => $key,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'type' => $definition['type'],
                'options' => collect($definition['options'])
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
                'value' => $values[$key],
            ];
        }

        return Inertia::render('Admin/Settings/Index', [
            'settings' => $settings,
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $before = $this->settings->all();
        $changes = [];

        foreach ($request->settingValues() as $key => $value) {
            if ($before[$key] === $value) {
                continue;
            }

            $this->settings->set($key, $value);
            $changes[$key] = ['from' => $before[$key], 'to' => $value];
        }

        if ($changes !== []) {
            $this->audit->record($request->user(), 'settings.updated', null, [
                'changes' => $changes,
            ], $request);
        }

        return redirect()
            ->route('admin.settings.edit')
            ->with('status', 'Settings saved.');
    }

    private function authorizeAdmin(): void
    {
        if (! request()->user()?->role->atLeast(Role::Admin)) {
            abort(403);
        }
    }
}
