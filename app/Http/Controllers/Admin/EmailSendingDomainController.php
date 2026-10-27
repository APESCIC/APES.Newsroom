<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SendingDomainStatus;
use App\Http\Controllers\Controller;
use App\Models\EmailSendingDomain;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\MailgunDomainService;
use App\Services\Mail\SendingDomainChecker;
use App\Services\Mailing\SendingDomainAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Settings → Email sending domains. Mailgun is only read: domains are
 * added and verified in the Mailgun dashboard; this page tracks which of them
 * newsletters may use and shows the DNS records each one needs.
 */
class EmailSendingDomainController extends Controller
{
    public const HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(
        private readonly SendingDomainChecker $checker,
        private readonly AuditLogger $audit,
    ) {}

    public function index(MailgunDomainService $mailgun, SendingDomainAlerts $alerts): Response
    {
        $domains = EmailSendingDomain::query()
            ->with('creator:id,name')
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
                'created_by' => $domain->creator?->name,
                'in_use' => $domain->newslettersUsing(),
            ]);

        return Inertia::render('Admin/Settings/EmailDomains/Index', [
            'domains' => $domains,
            'mailgunConfigured' => $mailgun->isConfigured(),
            'configuredMailgunDomain' => $mailgun->configuredDomain(),
            'dmarcRecommendation' => SendingDomainChecker::dmarcRecommendation(),
            'alerts' => $alerts->failing(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'domain' => Str::lower(trim((string) $request->input('domain'))),
            'mailgun_domain' => filled($request->input('mailgun_domain')) ? Str::lower(trim((string) $request->input('mailgun_domain'))) : null,
        ]);

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:'.self::HOSTNAME_PATTERN, Rule::unique('email_sending_domains', 'domain')],
            'label' => ['nullable', 'string', 'max:120'],
            'mailgun_domain' => ['nullable', 'string', 'max:253', 'regex:'.self::HOSTNAME_PATTERN],
        ], [
            'domain.regex' => 'Enter a domain name such as example.org.uk, without @ or https://.',
            'domain.unique' => 'That domain is already on the list.',
            'mailgun_domain.regex' => 'Enter the Mailgun domain name, such as mg.example.org.uk.',
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
}
