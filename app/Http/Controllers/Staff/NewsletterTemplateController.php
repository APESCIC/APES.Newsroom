<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplateVersion;
use App\Models\Newsletter;
use App\Services\EmailTemplates\NewsletterTemplateService;
use App\Support\EmailTemplates\NewsletterTemplateDefaults as Defaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff → Newsletters → email template: the default newsletter template
 * ("default") or one newsletter's own version (its id).
 */
class NewsletterTemplateController extends Controller
{
    public function __construct(private readonly NewsletterTemplateService $service) {}

    public function edit(Request $request, string $target): Response
    {
        Gate::authorize('manage-newsletter-templates');
        [$scope, $newsletter] = $this->target($target);

        return Inertia::render('Staff/NewsletterTemplates/Edit', [
            'target' => $target,
            'newsletter' => $newsletter ? ['id' => $newsletter->id, 'name' => $newsletter->name] : null,
            'template' => $this->service->current($scope),
            'defaults' => [
                'subject' => Defaults::subject(),
                'intro' => Defaults::intro(),
                'settings' => Defaults::settings(),
            ],
            'placeholders' => Defaults::PLACEHOLDERS,
            'postPlaceholders' => Defaults::POST_PLACEHOLDERS,
            'samplePlaceholders' => Defaults::samplePlaceholders(),
            'versions' => $this->versions($scope),
            'testRecipient' => $request->user()->email,
        ]);
    }

    public function update(Request $request, string $target): RedirectResponse
    {
        Gate::authorize('manage-newsletter-templates');
        [$scope] = $this->target($target);
        $validated = $this->validated($request);

        $this->service->save($scope, $validated['subject'], $validated['intro'], $validated['settings'], $request->user());

        return redirect()
            ->route('staff.newsletter-templates.edit', $target)
            ->with('status', 'Template saved.');
    }

    public function restore(Request $request, string $target, int $version): RedirectResponse
    {
        Gate::authorize('manage-newsletter-templates');
        [$scope] = $this->target($target);

        $this->service->restore($scope, $version, $request->user());

        return redirect()
            ->route('staff.newsletter-templates.edit', $target)
            ->with('status', 'Version restored.');
    }

    public function revert(Request $request, string $target): RedirectResponse
    {
        Gate::authorize('manage-newsletter-templates');
        [$scope, $newsletter] = $this->target($target);

        $this->service->revertToDefault($scope, $request->user());

        return redirect()
            ->route('staff.newsletter-templates.edit', $target)
            ->with('status', $newsletter
                ? 'Reverted. This newsletter now uses the default newsletter template.'
                : 'Reverted to the built-in newsletter email.');
    }

    /**
     * @return array{0: string, 1: Newsletter|null}
     */
    private function target(string $target): array
    {
        if ($target === 'default') {
            return [Defaults::SCOPE_DEFAULT, null];
        }

        $newsletter = Newsletter::query()->findOrFail((int) $target);

        return [Defaults::scopeFor($newsletter->id), $newsletter];
    }

    /**
     * @return array{subject: string, intro: string, settings: array<string, mixed>}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'settings' => ['required', 'array'],
            'settings.sign_off' => ['nullable', 'string', 'max:2000'],
            'settings.read_more_label' => ['required', 'string', 'max:80'],
            'settings.members_cta' => ['required', 'string', 'max:500'],
            'settings.paid_cta' => ['required', 'string', 'max:500'],
            'settings.show_hero' => ['required', 'boolean'],
            'settings.show_excerpt' => ['required', 'boolean'],
            'settings.show_full_post' => ['required', 'boolean'],
        ]);

        $settings = $validated['settings'];
        $settings['sign_off'] = (string) ($settings['sign_off'] ?? '');

        return [
            'subject' => $validated['subject'],
            'intro' => (string) ($validated['intro'] ?? ''),
            'settings' => $settings,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function versions(string $scope): array
    {
        return array_map(static fn (EmailTemplateVersion $version): array => [
            'id' => $version->id,
            'subject' => $version->subject,
            'intro' => $version->body,
            'settings' => Defaults::mergeSettings($version->settings),
            'editor' => $version->editor?->name,
            'created_at' => $version->created_at?->toIso8601String(),
        ], $this->service->versions($scope));
    }
}
