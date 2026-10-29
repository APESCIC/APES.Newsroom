<?php

namespace App\Http\Requests\Admin;

use App\Services\Mail\MailTransports;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Saves Admin → Settings → Mail sending. Super admins only; a transport that
 * cannot be used right now is refused so mail never goes to a dead mailer.
 */
class UpdateMailSendingSettingsRequest extends UpdateSettingsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('access-super-admin-area') ?? false;
    }

    protected function settingsPage(): ?string
    {
        return SettingDefinitions::PAGE_MAIL_SENDING;
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $transports = app(MailTransports::class);
                $input = fn (string $key, string $current) => (string) (Arr::get($validator->getData(), 'settings.'.$key) ?? $current);
                $system = $input(SettingDefinitions::MAIL_SYSTEM_TRANSPORT, $transports->systemTransport());
                $newsletters = $input(SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT, $transports->newsletterTransport());
                $fallback = $input(SettingDefinitions::MAIL_SYSTEM_FALLBACK, $transports->systemFallback());

                $checks = [
                    SettingDefinitions::MAIL_SYSTEM_TRANSPORT => $transports->unavailableReason($system),
                    SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT => $transports->unavailableReason($newsletters, forNewsletters: true),
                    SettingDefinitions::MAIL_SYSTEM_FALLBACK => match (true) {
                        $fallback === MailTransports::NONE => null,
                        $fallback === $system => 'The fallback must be a different transport from system mail.',
                        default => $transports->unavailableReason($fallback),
                    },
                ];

                $current = [
                    SettingDefinitions::MAIL_SYSTEM_TRANSPORT => $transports->systemTransport(),
                    SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT => $transports->newsletterTransport(),
                    SettingDefinitions::MAIL_SYSTEM_FALLBACK => $transports->systemFallback(),
                ];
                $chosen = [
                    SettingDefinitions::MAIL_SYSTEM_TRANSPORT => $system,
                    SettingDefinitions::MAIL_NEWSLETTER_TRANSPORT => $newsletters,
                    SettingDefinitions::MAIL_SYSTEM_FALLBACK => $fallback,
                ];
                $systemChanged = $system !== $current[SettingDefinitions::MAIL_SYSTEM_TRANSPORT];

                foreach ($checks as $key => $problem) {
                    $changed = $chosen[$key] !== $current[$key] || ($key === SettingDefinitions::MAIL_SYSTEM_FALLBACK && $systemChanged);
                    if ($problem !== null && $changed) {
                        $validator->errors()->add('settings.'.$key, $problem);
                    }
                }
            },
        ];
    }
}
