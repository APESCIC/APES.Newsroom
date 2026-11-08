<?php

namespace App\Services\EmailTemplates;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\EmailTemplates\NewsletterTemplateDefaults as Defaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Edits newsletter email templates (the default and per-newsletter versions).
 * Every save is a version; restore and revert are audit-logged.
 */
class NewsletterTemplateService
{
    public function __construct(
        private readonly NewsletterTemplates $templates,
        private readonly TemplateRenderer $renderer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What the editor starts from: the saved template, or for a newsletter
     * with no version of its own, the template it sends with today.
     *
     * @return array{subject: string, intro: string, settings: array<string, mixed>, is_overridden: bool, updated_at: string|null}
     */
    public function current(string $scope): array
    {
        $template = $this->templates->find($scope);
        $source = $template
            ?? ($scope !== Defaults::SCOPE_DEFAULT ? $this->templates->find(Defaults::SCOPE_DEFAULT) : null);

        return [
            'subject' => $source?->subject ?? Defaults::subject(),
            'intro' => $source?->body ?? Defaults::intro(),
            'settings' => Defaults::mergeSettings($source?->settings),
            'is_overridden' => $template !== null,
            'updated_at' => $template?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public function validate(string $subject, string $intro, array $settings): array
    {
        $errors = $this->renderer->validate($subject, $intro, Defaults::definition());
        $merged = Defaults::mergeSettings($settings);

        foreach (['sign_off' => 'sign-off', 'read_more_label' => 'button label'] as $key => $label) {
            foreach ($this->unknownTags($merged[$key], Defaults::PLACEHOLDERS) as $tag) {
                $errors[] = "Unknown placeholder {{{$tag}}} in the {$label}.";
            }
        }

        foreach (['members_cta' => 'members-only wording', 'paid_cta' => 'paid wording'] as $key => $label) {
            foreach ($this->unknownTags($merged[$key], Defaults::POST_PLACEHOLDERS) as $tag) {
                $errors[] = $tag === 'recipient_first_name'
                    ? "{{recipient_first_name}} can't be used in the {$label}."
                    : "Unknown placeholder {{{$tag}}} in the {$label}.";
            }
        }

        if (trim($merged['read_more_label']) === '') {
            $errors[] = 'The button label cannot be empty.';
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function save(string $scope, string $subject, string $intro, array $settings, User $editor): EmailTemplate
    {
        $this->authorize($editor);
        $this->assertScope($scope);

        $errors = $this->validate($subject, $intro, $settings);
        if ($errors !== []) {
            throw ValidationException::withMessages(['template' => $errors]);
        }

        $merged = Defaults::mergeSettings($settings);

        return DB::transaction(function () use ($scope, $subject, $intro, $merged, $editor): EmailTemplate {
            $template = EmailTemplate::query()->updateOrCreate(
                ['key' => Defaults::KEY, 'scope' => $scope],
                [
                    'subject' => $subject,
                    'body' => $intro,
                    'settings' => $merged,
                    'updated_by' => $editor->id,
                ],
            );

            $template->versions()->create([
                'subject' => $subject,
                'body' => $intro,
                'settings' => $merged,
                'editor_id' => $editor->id,
                'created_at' => now(),
            ]);

            $this->audit->record($editor, 'newsletter_template.updated', $template, [
                'scope' => $scope,
            ], request());

            return $template->fresh() ?? $template;
        });
    }

    /**
     * @return list<EmailTemplateVersion>
     */
    public function versions(string $scope): array
    {
        $template = $this->templates->find($scope);

        return $template === null ? [] : $template->versions()->with('editor:id,name')->get()->all();
    }

    public function restore(string $scope, int $versionId, User $editor): EmailTemplate
    {
        $this->authorize($editor);

        $version = EmailTemplateVersion::query()
            ->whereKey($versionId)
            ->whereHas('template', fn ($query) => $query->where('key', Defaults::KEY)->where('scope', $scope))
            ->firstOrFail();

        $saved = $this->save($scope, $version->subject, $version->body, $version->settings ?? [], $editor);

        $this->audit->record($editor, 'newsletter_template.restored', $saved, [
            'scope' => $scope,
            'version_id' => $versionId,
        ], request());

        return $saved;
    }

    public function revertToDefault(string $scope, User $editor): void
    {
        $this->authorize($editor);
        $this->assertScope($scope);

        $template = $this->templates->find($scope);
        if ($template === null) {
            return;
        }

        DB::transaction(function () use ($template, $editor, $scope): void {
            $this->audit->record($editor, 'newsletter_template.reverted', $template, [
                'scope' => $scope,
            ], request());

            $template->delete();
        });
    }

    public function authorize(User $user): void
    {
        Gate::forUser($user)->authorize('manage-newsletter-templates');
    }

    private function assertScope(string $scope): void
    {
        abort_unless(Defaults::isNewsletterScope($scope), 404);
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function unknownTags(string $value, array $allowed): array
    {
        return array_values(array_diff($this->renderer->extractTags($value), $allowed));
    }
}
