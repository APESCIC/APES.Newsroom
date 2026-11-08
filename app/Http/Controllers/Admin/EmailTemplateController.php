<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateVersion;
use App\Services\EmailTemplates\EmailTemplateService;
use App\Support\EmailTemplates\TemplateDefinition;
use App\Support\EmailTemplates\TemplateRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

/**
 * Admin → Settings → Email templates: list, edit, preview, test and version history
 * for system notification templates.
 */
class EmailTemplateController extends Controller
{
    public function __construct(
        private readonly EmailTemplateService $templates,
        private readonly TemplateRegistry $registry,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', EmailTemplate::class);

        return Inertia::render('Admin/Settings/EmailTemplates/Index', [
            'groups' => $this->templates->listForAdmin(),
            'groupLabels' => self::groupLabels(),
        ]);
    }

    public function edit(string $key): Response
    {
        Gate::authorize('viewAny', EmailTemplate::class);

        $definition = $this->definition($key);
        $override = $this->templates->findOverride($key);

        return Inertia::render('Admin/Settings/EmailTemplates/Edit', [
            'definition' => $this->serializeDefinition($definition),
            'subject' => $override?->subject ?? $definition->defaultSubject,
            'body' => $override?->body ?? $definition->defaultBody,
            'is_overridden' => $override !== null,
            'updated_at' => $override?->updated_at?->toIso8601String(),
            'versions' => $this->serializeVersions($key),
            'testRecipient' => request()->user()->email,
            'groupLabels' => self::groupLabels(),
        ]);
    }

    public function update(Request $request, string $key): RedirectResponse
    {
        Gate::authorize('update', EmailTemplate::class);
        $this->definition($key);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        try {
            $this->templates->save($key, $validated['subject'], $validated['body'], $request->user());
        } catch (ValidationException $exception) {
            throw $exception;
        }

        return redirect()
            ->route('admin.settings.email-templates.edit', $key)
            ->with('status', 'Template saved.');
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        Gate::authorize('viewAny', EmailTemplate::class);
        $this->definition($key);

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        $preview = $this->templates->preview(
            $key,
            $validated['subject'] ?? null,
            $validated['body'] ?? null,
        );

        return response()->json([
            'subject' => $preview['subject'],
            'html' => $preview['html'],
            'text' => $preview['text'],
        ]);
    }

    public function test(Request $request, string $key): JsonResponse
    {
        Gate::authorize('update', EmailTemplate::class);
        $this->definition($key);

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $this->templates->sendTest(
                $key,
                $request->user(),
                $validated['subject'] ?? null,
                $validated['body'] ?? null,
            );
        } catch (ValidationException $exception) {
            $message = $exception->errors()['test'][0] ?? null;
            if (is_string($message) && $message !== '') {
                return response()->json(['ok' => false, 'message' => $message], 429);
            }

            throw $exception;
        } catch (Throwable $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'The test email could not be sent.',
                'error' => $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Test email sent to '.$request->user()->email.'.',
        ]);
    }

    public function versions(string $key): JsonResponse
    {
        Gate::authorize('viewAny', EmailTemplate::class);
        $this->definition($key);

        return response()->json(['versions' => $this->serializeVersions($key)]);
    }

    public function restore(Request $request, string $key, int $version): RedirectResponse
    {
        Gate::authorize('update', EmailTemplate::class);
        $this->definition($key);

        $record = EmailTemplateVersion::query()->with('template')->findOrFail($version);
        if ($record->template === null || $record->template->key !== $key || $record->template->scope !== EmailTemplate::SCOPE_SYSTEM) {
            abort(404);
        }

        $this->templates->restore($version, $request->user());

        return redirect()
            ->route('admin.settings.email-templates.edit', $key)
            ->with('status', 'Version restored.');
    }

    public function revert(Request $request, string $key): RedirectResponse
    {
        Gate::authorize('update', EmailTemplate::class);
        $this->definition($key);

        $this->templates->revertToDefault($key, $request->user());

        return redirect()
            ->route('admin.settings.email-templates.edit', $key)
            ->with('status', 'Reverted to the default template.');
    }

    /**
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            TemplateRegistry::GROUP_ACCOUNT => 'Account',
            TemplateRegistry::GROUP_SECURITY => 'Security',
            TemplateRegistry::GROUP_MAILING_CONSENT => 'Mailing consent',
            TemplateRegistry::GROUP_STAFF_ALERTS => 'Staff alerts',
            TemplateRegistry::GROUP_TESTS => 'Tests',
        ];
    }

    private function definition(string $key): TemplateDefinition
    {
        try {
            return $this->registry->get($key);
        } catch (InvalidArgumentException) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDefinition(TemplateDefinition $definition): array
    {
        return [
            'key' => $definition->key,
            'name' => $definition->name,
            'group' => $definition->group,
            'is_security' => $definition->isSecurity,
            'allowed_placeholders' => $definition->allowedPlaceholders,
            'required_placeholders' => $definition->requiredPlaceholders,
            'default_subject' => $definition->defaultSubject,
            'default_body' => $definition->defaultBody,
            'locked_notice' => $definition->lockedNotice,
            'action_label' => $definition->actionLabel,
            'sample_placeholders' => $definition->samplePlaceholders,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serializeVersions(string $key): array
    {
        return array_map(static function (EmailTemplateVersion $version): array {
            return [
                'id' => $version->id,
                'subject' => $version->subject,
                'body' => $version->body,
                'editor' => $version->editor?->name,
                'created_at' => $version->created_at?->toIso8601String(),
            ];
        }, $this->templates->versions($key));
    }
}
