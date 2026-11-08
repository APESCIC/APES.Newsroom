<?php

namespace App\Support\EmailTemplates;

use App\Models\EmailTemplate;
use App\Models\Newsletter;
use Closure;

/**
 * The newsletter template a post's campaign uses, as offered in the post
 * editor: empty (automatic), the default, or a newsletter's own version.
 */
final class NewsletterTemplateChoice
{
    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['nullable', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
            $scope = (string) $value;

            if (! NewsletterTemplateDefaults::isNewsletterScope($scope)) {
                $fail('Choose a newsletter template from the list.');

                return;
            }

            $id = NewsletterTemplateDefaults::newsletterIdFromScope($scope);
            if ($id !== null && ! Newsletter::query()->whereKey($id)->exists()) {
                $fail('That newsletter no longer exists.');
            }
        }];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $versions = EmailTemplate::query()
            ->where('key', NewsletterTemplateDefaults::KEY)
            ->where('scope', 'like', NewsletterTemplateDefaults::SCOPE_DEFAULT.':%')
            ->pluck('scope')
            ->map(fn (string $scope) => NewsletterTemplateDefaults::newsletterIdFromScope($scope))
            ->filter()
            ->all();

        return [
            ['value' => '', 'label' => 'Automatic: the sending newsletter’s template'],
            ['value' => NewsletterTemplateDefaults::SCOPE_DEFAULT, 'label' => 'Default newsletter template'],
            ...Newsletter::query()
                ->whereIn('id', $versions)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Newsletter $newsletter) => [
                    'value' => NewsletterTemplateDefaults::scopeFor($newsletter->id),
                    'label' => $newsletter->name.' version',
                ])
                ->all(),
        ];
    }
}
