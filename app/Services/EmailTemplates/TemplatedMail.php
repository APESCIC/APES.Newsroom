<?php

namespace App\Services\EmailTemplates;

use App\Models\EmailTemplate;
use App\Support\EmailTemplates\TemplateRegistry;
use Closure;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Resolves a MailMessage from a DB override when one exists; otherwise runs
 * the notification's fallback so current wording stays unchanged.
 */
class TemplatedMail
{
    public function __construct(
        private readonly TemplateRegistry $registry,
        private readonly TemplateRenderer $renderer,
    ) {}

    /**
     * @param  array<string, string|int|float|null>  $placeholders
     * @param  (Closure(): MailMessage)|null  $fallback
     * @param  list<string>  $extraLines
     */
    public function build(
        string $key,
        array $placeholders = [],
        ?Closure $fallback = null,
        ?string $actionLabel = null,
        ?string $actionUrl = null,
        array $extraLines = [],
        bool $viaReset = false,
    ): MailMessage {
        $override = $this->findOverride($key);

        if ($override === null) {
            if ($fallback !== null) {
                return $fallback();
            }

            return $this->fromDefinition($key, $placeholders, $actionLabel, $actionUrl, $extraLines, $viaReset);
        }

        $definition = $this->registry->get($key);
        $subject = $this->renderer->renderSubject(
            $override->subject,
            $placeholders,
            $definition->allowedPlaceholders,
        );
        $body = $this->renderer->render(
            $override->body,
            $placeholders,
            $definition->allowedPlaceholders,
        );

        $message = (new MailMessage)->subject($subject);

        foreach ($this->paragraphs($body) as $paragraph) {
            $message->line($paragraph);
        }

        if ($actionLabel !== null && $actionUrl !== null) {
            $message->action($actionLabel, $actionUrl);
        }

        foreach ($extraLines as $line) {
            $message->line($line);
        }

        return $message;
    }

    public function hasOverride(string $key): bool
    {
        return $this->findOverride($key) !== null;
    }

    public function findOverride(string $key): ?EmailTemplate
    {
        return EmailTemplate::query()->system()->where('key', $key)->first();
    }

    /**
     * @param  array<string, string|int|float|null>  $placeholders
     * @return array{subject: string, html: string, text: string}
     */
    public function renderOverride(string $key, array $placeholders = [], ?string $subject = null, ?string $body = null): array
    {
        $definition = $this->registry->get($key);
        $override = $this->findOverride($key);
        $subjectTemplate = $subject ?? $override?->subject ?? $definition->defaultSubject;
        $bodyTemplate = $body ?? $override?->body ?? $definition->defaultBody;
        $renderedSubject = $this->renderer->renderSubject(
            $subjectTemplate,
            $placeholders,
            $definition->allowedPlaceholders,
        );
        $renderedBody = $this->renderer->render(
            $bodyTemplate,
            $placeholders,
            $definition->allowedPlaceholders,
        );
        $html = $this->renderer->toHtml($renderedBody);

        return [
            'subject' => $renderedSubject,
            'html' => $html,
            'text' => $this->renderer->toText($renderedBody),
        ];
    }

    /**
     * @param  array<string, string|int|float|null>  $placeholders
     * @param  list<string>  $extraLines
     */
    private function fromDefinition(
        string $key,
        array $placeholders,
        ?string $actionLabel,
        ?string $actionUrl,
        array $extraLines,
        bool $viaReset,
    ): MailMessage {
        $definition = $this->registry->get($key);
        $subject = $this->renderer->renderSubject(
            $definition->defaultSubject,
            $placeholders,
            $definition->allowedPlaceholders,
        );
        $body = $this->renderer->render(
            $definition->defaultBodyForContext($viaReset),
            $placeholders,
            $definition->allowedPlaceholders,
        );

        $message = (new MailMessage)->subject($subject);

        foreach ($this->paragraphs($body) as $paragraph) {
            $message->line($paragraph);
        }

        if ($actionLabel !== null && $actionUrl !== null) {
            $message->action($actionLabel, $actionUrl);
        }

        foreach ($extraLines as $line) {
            $message->line($line);
        }

        return $message;
    }

    /**
     * @return list<string>
     */
    private function paragraphs(string $body): array
    {
        $parts = preg_split('/\n\s*\n/', trim($body)) ?: [];

        return array_values(array_filter(array_map(
            static fn (string $part): string => trim(str_replace("\n", ' ', $part)),
            $parts,
        ), static fn (string $part): bool => $part !== ''));
    }
}
