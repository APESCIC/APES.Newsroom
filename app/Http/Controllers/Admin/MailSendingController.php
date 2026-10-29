<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMailSendingSettingsRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\MailTransports;
use App\Services\Mail\TestSendThrottle;
use App\Services\Mail\TransportTester;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Settings → Mail sending: which transport system mail and
 * newsletters use. Super admins change it; admins view and test it.
 */
class MailSendingController extends Controller
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly MailTransports $transports,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('access-admin-area');

        $settings = [];
        foreach (SettingDefinitions::forPage(SettingDefinitions::PAGE_MAIL_SENDING) as $key => $definition) {
            $settings[] = [
                'key' => $key,
                'section' => $definition['section'],
                'label' => $definition['label'],
                'help' => $definition['help'],
                'type' => $definition['type'],
                'options' => collect($definition['options'] ?? [])
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values()
                    ->all(),
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'unit' => $definition['unit'] ?? null,
                'value' => $this->settings->get($key),
            ];
        }

        $transports = collect(SettingDefinitions::transportOptions())->map(fn (string $label, string $value) => [
            'value' => $value,
            'label' => $label,
            'configured' => $this->transports->isConfigured($value),
            'system_problem' => $this->transports->unavailableReason($value),
            'newsletter_problem' => $this->transports->unavailableReason($value, forNewsletters: true),
        ])->values()->all();

        return Inertia::render('Admin/Settings/MailSending/Index', [
            'settings' => $settings,
            'transports' => $transports,
            'effectiveNewsletterTransport' => $this->transports->effectiveNewsletterTransport(),
            'cloudron' => [
                'detected' => (bool) config('mail.cloudron_forces_from', false),
                'mailer' => $this->transports->environmentMailer(),
                'from' => config('mail.from.address'),
            ],
            'features' => self::features(),
            'lastTests' => [
                'system' => TransportTester::lastResult('system'),
                'newsletters' => TransportTester::lastResult('newsletters'),
            ],
            'canManage' => $request->user()->can('access-super-admin-area'),
            'testRecipient' => $request->user()->email,
        ]);
    }

    public function update(UpdateMailSendingSettingsRequest $request): RedirectResponse
    {
        $changes = [];
        foreach ($request->settingValues() as $key => $value) {
            $before = $this->settings->get($key);
            if ($before === $value && $this->settings->isStored($key)) {
                continue;
            }

            $this->settings->set($key, $value);
            if ($before !== $value) {
                $changes[$key] = ['from' => $before, 'to' => $value];
            }
        }

        if ($changes !== []) {
            $this->audit->record($request->user(), 'mail_sending.updated', null, ['changes' => $changes], $request);
        }

        $this->transports->applySystemMailer(app('mail.manager'));

        return redirect()->route('admin.settings.mail-sending.index')->with('status', 'Mail sending saved. Send a test email to check it works.');
    }

    public function test(Request $request, TransportTester $tester, TestSendThrottle $throttle): JsonResponse
    {
        Gate::authorize('access-admin-area');

        $validated = $request->validate([
            'purpose' => ['required', Rule::in(array_keys(TransportTester::PURPOSES))],
            'mode' => ['required', Rule::in(TransportTester::MODES)],
            'extra_recipient' => ['nullable', 'string', 'email', 'max:255'],
        ], [
            'extra_recipient.email' => 'Enter a valid email address for the extra recipient.',
        ]);

        $limited = $throttle->attempt($request->user(), 'transport:'.$validated['purpose'], 'This transport');
        if ($limited !== null) {
            return response()->json(['message' => $limited], 429);
        }

        $extra = filled($validated['extra_recipient'] ?? null) ? Str::lower(trim((string) $validated['extra_recipient'])) : null;

        return response()->json($tester->run($validated['purpose'], $validated['mode'], $request->user(), $extra, $request));
    }

    /**
     * What each transport supports, for the availability notes.
     *
     * @return list<array{feature: string, cloudron_smtp: string, mailgun_api: string, custom_smtp: string}>
     */
    public static function features(): array
    {
        return [
            [
                'feature' => 'Per-domain From addresses',
                'cloudron_smtp' => 'No: Cloudron sends From its own address, and replies go to the newsletter.',
                'mailgun_api' => 'Yes: each From domain sends through its own Mailgun domain.',
                'custom_smtp' => 'Depends on the provider: the From domain must be verified there.',
            ],
            [
                'feature' => 'Bounce, complaint and unsubscribe handling',
                'cloudron_smtp' => 'No.',
                'mailgun_api' => 'Yes, through signed webhooks on each sending domain.',
                'custom_smtp' => 'Only with Mailgun SMTP (smtp.eu.mailgun.org) and the webhooks registered.',
            ],
            [
                'feature' => 'Open and click tracking',
                'cloudron_smtp' => 'Newsroom tracking links only, with the reader\'s consent.',
                'mailgun_api' => 'Mailgun tracking per domain, with the reader\'s consent.',
                'custom_smtp' => 'Newsroom tracking links only, with the reader\'s consent.',
            ],
            [
                'feature' => 'Bulk sending',
                'cloudron_smtp' => 'Small lists only; Cloudron limits outbound volume.',
                'mailgun_api' => 'Yes.',
                'custom_smtp' => 'Depends on the provider. ZeptoMail is for system mail only.',
            ],
            [
                'feature' => 'Sending domain status checks',
                'cloudron_smtp' => 'Only when a Mailgun API key is set.',
                'mailgun_api' => 'Yes.',
                'custom_smtp' => 'Only when a Mailgun API key is set.',
            ],
        ];
    }
}
