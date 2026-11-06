<?php

namespace App\Services\EmailTemplates;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentAuthentication;
use App\Services\Mail\TestSendThrottle;
use App\Support\EmailTemplates\TemplateDefinition;
use App\Support\EmailTemplates\TemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EmailTemplateService
{
    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly TemplateRenderer $renderer,
        private readonly TemplatedMail $templatedMail,
        private readonly AuditLogger $audit,
        private readonly RecentAuthentication $recent,
        private readonly TestSendThrottle $throttle,
    ) {}

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function listForAdmin(): array
    {
        $overrides = EmailTemplate::query()->get()->keyBy('key');
        $grouped = [];

        foreach ($this->registry->groups() as $group => $definitions) {
            $grouped[$group] = array_map(function (TemplateDefinition $def) use ($overrides): array {
                $override = $overrides->get($def->key);

                return [
                    'key' => $def->key,
                    'name' => $def->name,
                    'group' => $def->group,
                    'is_security' => $def->isSecurity,
                    'has_override' => $override !== null,
                    'updated_at' => $override?->updated_at?->toIso8601String(),
                    'default_subject' => $def->defaultSubject,
                ];
            }, $definitions);
        }

        return $grouped;
    }

    public function findOverride(string $key): ?EmailTemplate
    {
        $this->registry->get($key);

        return $this->templatedMail->findOverride($key);
    }

    public function assertCanEdit(User $user, TemplateDefinition $def): void
    {
        if (! $user->can('access-admin-area')) {
            abort(403);
        }

        if ($def->isSecurity && ! $this->recent->isRecent(request(), 300)) {
            abort(423, 'Please confirm your identity to continue.');
        }
    }

    public function save(string $key, string $subject, string $body, User $editor): EmailTemplate
    {
        $definition = $this->registry->get($key);
        $this->assertCanEdit($editor, $definition);

        $errors = $this->renderer->validate($subject, $body, $definition);
        if ($errors !== []) {
            throw ValidationException::withMessages(['template' => $errors]);
        }

        return DB::transaction(function () use ($key, $subject, $body, $editor): EmailTemplate {
            $template = EmailTemplate::query()->updateOrCreate(
                ['key' => $key],
                [
                    'subject' => $subject,
                    'body' => $body,
                    'updated_by' => $editor->id,
                ],
            );

            $template->versions()->create([
                'subject' => $subject,
                'body' => $body,
                'editor_id' => $editor->id,
                'created_at' => now(),
            ]);

            $this->audit->record($editor, 'email_template.updated', $template, [
                'key' => $key,
            ], request());

            return $template->fresh() ?? $template;
        });
    }

    /**
     * @return list<EmailTemplateVersion>
     */
    public function versions(string $key): array
    {
        $template = $this->findOverride($key);

        if ($template === null) {
            return [];
        }

        return $template->versions()->with('editor:id,name')->get()->all();
    }

    public function restore(int $versionId, User $editor): EmailTemplate
    {
        $version = EmailTemplateVersion::query()->with('template')->findOrFail($versionId);
        $template = $version->template;

        if ($template === null) {
            throw new InvalidArgumentException('Version has no template.');
        }

        $saved = $this->save($template->key, $version->subject, $version->body, $editor);

        $this->audit->record($editor, 'email_template.restored', $saved, [
            'key' => $saved->key,
            'version_id' => $versionId,
        ], request());

        return $saved;
    }

    public function revertToDefault(string $key, User $editor): void
    {
        $definition = $this->registry->get($key);
        $this->assertCanEdit($editor, $definition);

        $template = $this->findOverride($key);
        if ($template === null) {
            return;
        }

        DB::transaction(function () use ($template, $editor, $key): void {
            $this->audit->record($editor, 'email_template.reverted', $template, [
                'key' => $key,
            ], request());

            $template->delete();
        });
    }

    /**
     * @param  array<string, string>|null  $placeholders
     * @return array{subject: string, html: string, text: string, placeholders: array<string, string>}
     */
    public function preview(string $key, ?string $subject = null, ?string $body = null, ?array $placeholders = null): array
    {
        $definition = $this->registry->get($key);
        $samples = $placeholders ?? $definition->samplePlaceholders;
        $rendered = $this->templatedMail->renderOverride($key, $samples, $subject, $body);

        return [
            ...$rendered,
            'placeholders' => $samples,
        ];
    }

    public function sendTest(string $key, User $editor, ?string $subject = null, ?string $body = null): void
    {
        $definition = $this->registry->get($key);
        $this->assertCanEdit($editor, $definition);

        $refusal = $this->throttle->attempt($editor, 'email-template:'.$key, 'This template');
        if ($refusal !== null) {
            throw ValidationException::withMessages(['test' => $refusal]);
        }

        $preview = $this->preview($key, $subject, $body);

        Mail::html($preview['html'], function ($message) use ($editor, $preview): void {
            $message->to($editor->email, $editor->name)
                ->subject($preview['subject']);
            $message->text($preview['text']);
        });

        $this->audit->record($editor, 'email_template.test_sent', $this->findOverride($key), [
            'key' => $key,
            'to' => $editor->email,
        ], request());
    }
}
