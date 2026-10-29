<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SenderAddressPurpose;
use App\Enums\SendingDomainStatus;
use App\Http\Controllers\Controller;
use App\Models\EmailSenderAddress;
use App\Models\EmailSendingDomain;
use App\Models\Newsletter;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\MailgunDomainService;
use App\Services\Mail\SendingDomainChecker;
use App\Services\Mail\SendingDomainTester;
use App\Services\Mail\TestSendThrottle;
use App\Services\Mailing\DefaultReplyTo;
use App\Services\Mailing\SenderDomains;
use App\Services\Mailing\SendingDomainAlerts;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Settings → Email sending domains. Mailgun domains are added and
 * verified in the Mailgun dashboard; this page tracks which of them
 * newsletters may use, their allowed From addresses and the DNS records each
 * one needs, and sends test emails through them.
 */
class EmailSendingDomainController extends Controller
{
    public const HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(
        private readonly SendingDomainChecker $checker,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, MailgunDomainService $mailgun, SendingDomainAlerts $alerts): Response
    {
        $domains = EmailSendingDomain::query()
            ->with(['creator:id,name', 'addresses'])
            ->orderBy('domain')
            ->get()
            ->map(fn (EmailSendingDomain $domain) => [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'label' => $domain->label,
                'status' => $domain->status->value,
                'mailgun_domain' => $mailgun->mailgunDomainFor($domain->domain, $domain->mailgun_domain),
                'mailgun_domain_override' => $domain->mailgun_domain,
                'last_check' => $domain->last_check,
                'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
                'verified_at' => $domain->verified_at?->toIso8601String(),
                'last_test' => $domain->last_test_result,
                'created_by' => $domain->creator?->name,
                'in_use' => $domain->newslettersUsing(),
                'addresses' => $domain->addresses->map(fn (EmailSenderAddress $address) => [
                    'id' => $address->id,
                    'address' => $address->address,
                    'purpose' => $address->purpose->value,
                    'label' => $address->label,
                    'enabled' => $address->enabled,
                    'last_test' => $address->last_test_result,
                ])->values(),
            ]);

        return Inertia::render('Admin/Settings/EmailDomains/Index', [
            'domains' => $domains,
            'mailgunConfigured' => $mailgun->isConfigured(),
            'configuredMailgunDomain' => $mailgun->configuredDomain(),
            'dmarcRecommendation' => SendingDomainChecker::dmarcRecommendation(),
            'alerts' => $alerts->failing(),
            'defaultReplyTo' => DefaultReplyTo::get(),
            'defaultReplyToProblem' => filled(DefaultReplyTo::get()) ? SenderDomains::replyToProblem((string) DefaultReplyTo::get()) : null,
            'testRecipient' => $request->user()->email,
            'offerTestAddressId' => $request->session()->get('offer_test_address'),
            'purposes' => collect(SenderAddressPurpose::cases())->map(fn (SenderAddressPurpose $purpose) => [
                'value' => $purpose->value,
                'label' => $purpose->label(),
            ])->values(),
        ]);
    }

    public function storeAddress(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        $validated = $this->validatedAddress($request, $emailSendingDomain);

        $address = $emailSendingDomain->addresses()->create([
            ...$validated,
            'enabled' => true,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record($request->user(), 'email_sender_address.added', $address, [
            'domain' => $emailSendingDomain->domain,
            'address' => $address->address,
            'purpose' => $address->purpose->value,
        ], $request);

        return back()
            ->with('status', "Added {$address->address}. Send a test email to check it works.")
            ->with('offer_test_address', $address->id);
    }

    public function updateAddress(Request $request, EmailSenderAddress $emailSenderAddress): RedirectResponse
    {
        $domain = $emailSenderAddress->domain;
        $validated = $this->validatedAddress($request, $domain, $emailSenderAddress);

        if ($validated['address'] !== $emailSenderAddress->address) {
            $inUse = Newsletter::query()->whereRaw('LOWER(sender_email) = ?', [$emailSenderAddress->address])->orderBy('name')->pluck('name');
            if ($inUse->isNotEmpty()) {
                return back()->withErrors([
                    'address' => "{$emailSenderAddress->address} is the From address of ".$inUse->implode(', ')
                        .'. Change those newsletters first, or add the new address alongside it.',
                ]);
            }
        }

        $before = $emailSenderAddress->only(['address', 'purpose', 'label']);
        $emailSenderAddress->update($validated);
        $after = $emailSenderAddress->only(['address', 'purpose', 'label']);

        $changes = [];
        foreach ($after as $field => $value) {
            $old = $before[$field] instanceof SenderAddressPurpose ? $before[$field]->value : $before[$field];
            $new = $value instanceof SenderAddressPurpose ? $value->value : $value;
            if ($old !== $new) {
                $changes[$field] = ['from' => $old, 'to' => $new];
            }
        }

        if ($changes !== []) {
            $this->audit->record($request->user(), 'email_sender_address.updated', $emailSenderAddress, [
                'domain' => $domain->domain,
                'address' => $emailSenderAddress->address,
                'changes' => $changes,
            ], $request);
        }

        return back()
            ->with('status', "Saved {$emailSenderAddress->address}.")
            ->with('offer_test_address', $emailSenderAddress->id);
    }

    public function disableAddress(Request $request, EmailSenderAddress $emailSenderAddress): RedirectResponse
    {
        return $this->setAddressEnabled($request, $emailSenderAddress, false);
    }

    public function enableAddress(Request $request, EmailSenderAddress $emailSenderAddress): RedirectResponse
    {
        return $this->setAddressEnabled($request, $emailSenderAddress, true);
    }

    public function updateDefaultReplyTo(Request $request): RedirectResponse
    {
        $request->merge(['default_reply_to' => filled($request->input('default_reply_to')) ? Str::lower(trim((string) $request->input('default_reply_to'))) : null]);
        $validated = $request->validate([
            'default_reply_to' => [
                'nullable', 'string', 'email', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) {
                    $problem = filled($value) ? SenderDomains::replyToProblem((string) $value) : null;
                    if ($problem !== null) {
                        $fail($problem);
                    }
                },
            ],
        ]);

        $previous = DefaultReplyTo::get();
        $next = filled($validated['default_reply_to'] ?? null) ? (string) $validated['default_reply_to'] : null;
        if ($previous === $next) {
            return back();
        }

        DefaultReplyTo::set($next);
        $this->audit->record($request->user(), 'email_sending_domain.default_reply_to_changed', null, [
            'from' => $previous,
            'to' => $next,
        ], $request);

        return back()->with('status', $next === null ? 'Removed the default reply-to address.' : "Default reply-to set to {$next}.");
    }

    public function testDomain(Request $request, EmailSendingDomain $emailSendingDomain, SendingDomainTester $tester, TestSendThrottle $throttle): JsonResponse
    {
        return $this->sendTest($request, $emailSendingDomain, null, $tester, $throttle);
    }

    public function testAddress(Request $request, EmailSenderAddress $emailSenderAddress, SendingDomainTester $tester, TestSendThrottle $throttle): JsonResponse
    {
        return $this->sendTest($request, $emailSenderAddress->domain, $emailSenderAddress, $tester, $throttle);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'domain' => Str::lower(trim((string) $request->input('domain'))),
            'mailgun_domain' => filled($request->input('mailgun_domain')) ? Str::lower(trim((string) $request->input('mailgun_domain'))) : null,
        ]);

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:'.self::HOSTNAME_PATTERN, Rule::unique('email_sending_domains', 'domain'), Rule::unique('email_sending_domains', 'mailgun_domain')],
            'label' => ['nullable', 'string', 'max:120'],
            'mailgun_domain' => [
                'nullable', 'string', 'max:253', 'regex:'.self::HOSTNAME_PATTERN,
                Rule::unique('email_sending_domains', 'mailgun_domain'),
                Rule::unique('email_sending_domains', 'domain'),
            ],
        ], [
            'domain.regex' => 'Enter a domain name such as example.org.uk, without @ or https://.',
            'domain.unique' => 'That domain is already on the list.',
            'mailgun_domain.regex' => 'Enter the Mailgun domain name, such as mg.example.org.uk.',
            'mailgun_domain.unique' => 'That Mailgun domain is already used by another sending domain.',
        ]);

        $domain = EmailSendingDomain::query()->create([
            'domain' => $validated['domain'],
            'label' => filled($validated['label'] ?? null) ? trim((string) $validated['label']) : null,
            'mailgun_domain' => $validated['mailgun_domain'] ?? null,
            'status' => SendingDomainStatus::Pending,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record($request->user(), 'email_sending_domain.added', $domain, [
            'domain' => $domain->domain,
            'old_status' => null,
            'new_status' => SendingDomainStatus::Pending->value,
        ], $request);

        $this->checker->check($domain, $request->user());

        return back()->with('status', "Added {$domain->domain}. ".$domain->fresh()->last_check['message']);
    }

    public function recheck(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        $result = $this->checker->check($emailSendingDomain, $request->user());

        return back()->with('status', "Checked {$emailSendingDomain->domain}. {$result['message']}");
    }

    public function disable(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        $previous = $emailSendingDomain->status;
        if ($previous === SendingDomainStatus::Disabled) {
            return back();
        }

        $emailSendingDomain->update(['status' => SendingDomainStatus::Disabled]);
        $this->audit->record($request->user(), 'email_sending_domain.disabled', $emailSendingDomain, [
            'domain' => $emailSendingDomain->domain,
            'old_status' => $previous->value,
            'new_status' => SendingDomainStatus::Disabled->value,
        ], $request);

        return back()->with('status', "Disabled {$emailSendingDomain->domain}. Newsletters using it cannot send until it is enabled again.");
    }

    public function enable(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        if ($emailSendingDomain->status !== SendingDomainStatus::Disabled) {
            return back();
        }

        $emailSendingDomain->update(['status' => SendingDomainStatus::Pending]);
        $this->audit->record($request->user(), 'email_sending_domain.enabled', $emailSendingDomain, [
            'domain' => $emailSendingDomain->domain,
            'old_status' => SendingDomainStatus::Disabled->value,
            'new_status' => SendingDomainStatus::Pending->value,
        ], $request);

        $result = $this->checker->check($emailSendingDomain, $request->user());

        return back()->with('status', "Enabled {$emailSendingDomain->domain}. {$result['message']}");
    }

    public function destroy(Request $request, EmailSendingDomain $emailSendingDomain): RedirectResponse
    {
        $inUse = $emailSendingDomain->newslettersUsing();
        if ($inUse !== []) {
            return back()->withErrors([
                'remove' => "{$emailSendingDomain->domain} is used by ".collect($inUse)->pluck('name')->implode(', ')
                    .'. Change those newsletters first, or disable the domain instead of removing it.',
            ]);
        }

        $this->audit->record($request->user(), 'email_sending_domain.removed', $emailSendingDomain, [
            'domain' => $emailSendingDomain->domain,
            'old_status' => $emailSendingDomain->status->value,
            'new_status' => null,
        ], $request);
        $emailSendingDomain->delete();

        return back()->with('status', "Removed {$emailSendingDomain->domain}.");
    }

    /**
     * @return array{address: string, purpose: string, label: string|null}
     */
    private function validatedAddress(Request $request, EmailSendingDomain $domain, ?EmailSenderAddress $existing = null): array
    {
        $request->merge(['address' => Str::lower(trim((string) $request->input('address')))]);
        $suffixes = $domain->addressSuffixes();

        $validated = $request->validate([
            'address' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('email_sender_addresses', 'address')->ignore($existing?->id),
                function (string $attribute, mixed $value, Closure $fail) use ($suffixes) {
                    $email = (string) $value;
                    $problem = SenderDomains::localPartProblem($email);
                    if ($problem === null && ! Str::endsWith($email, $suffixes)) {
                        $problem = 'The address must end with '.implode(' or ', $suffixes).'.';
                    }
                    if ($problem !== null) {
                        $fail($problem);
                    }
                },
            ],
            'purpose' => ['required', Rule::enum(SenderAddressPurpose::class)],
            'label' => ['nullable', 'string', 'max:120'],
        ], [
            'address.unique' => 'That address is already on the list.',
        ]);

        return [
            'address' => $validated['address'],
            'purpose' => $validated['purpose'],
            'label' => filled($validated['label'] ?? null) ? trim((string) $validated['label']) : null,
        ];
    }

    private function setAddressEnabled(Request $request, EmailSenderAddress $address, bool $enabled): RedirectResponse
    {
        if ($address->enabled === $enabled) {
            return back();
        }

        $address->update(['enabled' => $enabled]);
        $this->audit->record($request->user(), $enabled ? 'email_sender_address.enabled' : 'email_sender_address.disabled', $address, [
            'domain' => $address->domain->domain,
            'address' => $address->address,
        ], $request);

        return back()->with('status', ($enabled ? 'Enabled ' : 'Disabled ').$address->address.'.');
    }

    private function sendTest(Request $request, EmailSendingDomain $domain, ?EmailSenderAddress $address, SendingDomainTester $tester, TestSendThrottle $throttle): JsonResponse
    {
        $extra = $this->validatedExtraRecipient($request);

        $refusal = match (true) {
            ! $domain->isEnabled() => "{$domain->domain} is disabled. Enable it before sending a test.",
            $address !== null && ! $address->enabled => "{$address->address} is disabled. Enable it before sending a test.",
            default => null,
        };
        if ($refusal !== null) {
            return response()->json(['message' => $refusal], 422);
        }

        $limited = $throttle->attempt($request->user(), 'domain:'.$domain->id);
        if ($limited !== null) {
            return response()->json(['message' => $limited], 429);
        }

        return response()->json($tester->send($domain, $address, $request->user(), $extra, $request));
    }

    private function validatedExtraRecipient(Request $request): ?string
    {
        $validated = $request->validate([
            'extra_recipient' => ['nullable', 'string', 'email', 'max:255'],
        ], [
            'extra_recipient.email' => 'Enter a valid email address for the extra recipient.',
        ]);

        return filled($validated['extra_recipient'] ?? null) ? Str::lower(trim((string) $validated['extra_recipient'])) : null;
    }
}
