<?php

namespace App\Services\EmailTemplates;

use App\Models\EmailTemplate;
use App\Models\MailingContact;
use App\Models\Newsletter;
use App\Support\EmailTemplates\NewsletterTemplateDefaults as Defaults;
use DomainException;
use Illuminate\Support\Str;

/**
 * Resolves which newsletter template a campaign uses and renders its editable
 * fragments. The layout, test banner, footer, unsubscribe and preferences
 * links and List-Unsubscribe headers are never part of a template.
 */
class NewsletterTemplates
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    public function find(string $scope): ?EmailTemplate
    {
        return EmailTemplate::query()
            ->where('key', Defaults::KEY)
            ->where('scope', $scope)
            ->first();
    }

    /**
     * Which template each newsletter sends with: its own version, the edited
     * default, or the built-in email.
     *
     * @param  iterable<Newsletter>  $newsletters
     * @return array<int, 'own'|'default_edited'|'built_in'>
     */
    public function usage(iterable $newsletters): array
    {
        $scopes = EmailTemplate::query()->where('key', Defaults::KEY)->pluck('scope')->all();
        $defaultEdited = in_array(Defaults::SCOPE_DEFAULT, $scopes, true);
        $usage = [];

        foreach ($newsletters as $newsletter) {
            $usage[$newsletter->id] = match (true) {
                in_array(Defaults::scopeFor($newsletter->id), $scopes, true) => 'own',
                $defaultEdited => 'default_edited',
                default => 'built_in',
            };
        }

        return $usage;
    }

    /**
     * @return 'own'|'default_edited'|'built_in'
     */
    public function usageFor(Newsletter $newsletter): string
    {
        return $this->usage([$newsletter])[$newsletter->id];
    }

    /**
     * A choice of "newsletter" (the default) or "newsletter:<id>" wins; with no
     * choice the sending newsletter's own version is used, then the default.
     * Null means nothing is saved and the built-in email is sent unchanged.
     */
    public function resolve(?Newsletter $newsletter, ?string $choice = null): ?EmailTemplate
    {
        $scopes = [];

        if ($choice !== null && Defaults::isNewsletterScope($choice)) {
            $scopes[] = $choice;
        } elseif ($newsletter !== null) {
            $scopes[] = Defaults::scopeFor($newsletter->id);
        }

        $scopes[] = Defaults::SCOPE_DEFAULT;

        foreach (array_unique($scopes) as $scope) {
            $template = $this->find($scope);
            if ($template !== null) {
                return $template;
            }
        }

        return null;
    }

    /**
     * The template as frozen into a campaign snapshot, so later edits never
     * change a campaign that was already created.
     *
     * @return array{scope: string, template_id: int|null, version_id: int|null, subject: string, intro: string, settings: array<string, mixed>}|null
     */
    public function snapshot(?EmailTemplate $template): ?array
    {
        if ($template === null) {
            return null;
        }

        return [
            'scope' => $template->scope,
            'template_id' => $template->id,
            'version_id' => $template->versions()->value('id'),
            'subject' => $template->subject,
            'intro' => $template->body,
            'settings' => Defaults::mergeSettings($template->settings),
        ];
    }

    /**
     * An unsaved draft from the editor, shaped like a snapshot.
     *
     * @param  array<string, mixed>  $settings
     * @return array{scope: string, template_id: null, version_id: null, subject: string, intro: string, settings: array<string, mixed>}
     */
    public function draft(string $scope, string $subject, string $intro, array $settings): array
    {
        return [
            'scope' => $scope,
            'template_id' => null,
            'version_id' => null,
            'subject' => $subject,
            'intro' => $intro,
            'settings' => Defaults::mergeSettings($settings),
        ];
    }

    /**
     * Placeholder values known when the campaign is created.
     *
     * @param  array<string, mixed>  $post  title, excerpt, author, read_more_url, channel_label
     * @return array<string, string>
     */
    public function postPlaceholders(array $post, ?Newsletter $newsletter): array
    {
        return [
            'post_title' => (string) ($post['title'] ?? ''),
            'post_excerpt' => (string) ($post['excerpt'] ?? ''),
            'post_author' => (string) ($post['author'] ?? ''),
            'read_more_url' => (string) ($post['read_more_url'] ?? ''),
            'newsletter_name' => (string) ($newsletter?->name ?? ($post['channel_label'] ?? config('app.name'))),
            'channel_label' => (string) ($post['channel_label'] ?? ''),
            'site_name' => (string) config('app.name'),
        ];
    }

    /**
     * Wording added to the excerpt of a members-only or paid post.
     *
     * @param  array<string, mixed>|null  $template
     * @param  array<string, string>  $placeholders
     */
    public function gatedCallToAction(?array $template, string $visibility, array $placeholders): string
    {
        $settings = Defaults::mergeSettings($template['settings'] ?? null);
        $wording = $visibility === 'paid' ? $settings['paid_cta'] : $settings['members_cta'];

        try {
            return trim($this->renderer->renderSubject($wording, $placeholders, Defaults::POST_PLACEHOLDERS));
        } catch (DomainException) {
            return $visibility === 'paid' ? Defaults::PAID_CTA : Defaults::MEMBERS_CTA;
        }
    }

    /**
     * Renders the editable parts of a templated campaign email.
     *
     * @param  array<string, mixed>  $template
     * @param  array<string, string>  $placeholders
     * @return array{subject: string, intro_html: string, sign_off_html: string, read_more_label: string, show_hero: bool, show_excerpt: bool, show_full_post: bool}
     */
    public function render(array $template, array $placeholders): array
    {
        $settings = Defaults::mergeSettings(is_array($template['settings'] ?? null) ? $template['settings'] : null);
        $allowed = Defaults::PLACEHOLDERS;

        $subject = trim($this->renderer->renderSubject((string) ($template['subject'] ?? ''), $placeholders, $allowed));
        $label = trim($this->renderer->renderSubject($settings['read_more_label'], $placeholders, $allowed));

        return [
            'subject' => $subject !== '' ? $subject : (string) ($placeholders['post_title'] ?? config('app.name')),
            'intro_html' => $this->markdown((string) ($template['intro'] ?? ''), $placeholders),
            'sign_off_html' => $this->markdown($settings['sign_off'], $placeholders),
            'read_more_label' => $label !== '' ? $label : 'Read the full story',
            'show_hero' => $settings['show_hero'],
            'show_excerpt' => $settings['show_excerpt'],
            'show_full_post' => $settings['show_full_post'],
        ];
    }

    public function firstNameFor(string $email): string
    {
        $contact = MailingContact::query()
            ->with('user:id,name')
            ->forNormalizedEmail($email)
            ->first();

        $name = trim((string) ($contact?->name ?: $contact?->user?->name ?: ''));

        return $name !== '' ? Str::before($name, ' ') : 'there';
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    private function markdown(string $markdown, array $placeholders): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        return $this->renderer->toHtml($this->renderer->render($markdown, $placeholders, Defaults::PLACEHOLDERS));
    }
}
