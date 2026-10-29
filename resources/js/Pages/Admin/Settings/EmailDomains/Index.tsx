import { router, useForm, usePage } from '@inertiajs/react';
import { Ban, Settings, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import WorkspaceLayout from '../../../../Components/Layout/WorkspaceLayout';
import ActionMenu, { type MenuAction } from '../../../../Components/Workspace/ui/ActionMenu';
import Badge, { type BadgeTone } from '../../../../Components/Workspace/ui/Badge';
import ConfirmDialog from '../../../../Components/Workspace/ui/ConfirmDialog';
import PillButton from '../../../../Components/Workspace/ui/PillButton';
import DefaultReplyToForm from '../../../../Components/Admin/EmailDomains/DefaultReplyToForm';
import SenderAddresses from '../../../../Components/Admin/EmailDomains/SenderAddresses';
import TestSendPanel from '../../../../Components/Admin/EmailDomains/TestSendPanel';
import type { PurposeOption, SenderAddress, TestResult } from '../../../../Components/Admin/EmailDomains/types';
import type { SharedPageProps } from '../../../../types/page';

export type SendingDomainStatus = 'pending' | 'verified' | 'failed' | 'disabled';

type DnsRecord = {
    purpose: string;
    type: string;
    name: string;
    value: string;
    valid: boolean;
};

type LastCheck = {
    checked_at: string;
    mailgun_domain: string;
    found: boolean;
    checks: { mailgun_state: string | null; spf: boolean; dkim: boolean; dmarc: boolean };
    records: DnsRecord[];
    dmarc: { name: string; value: string | null; recommendation: string };
    error: string | null;
    message: string;
};

type NewsletterUse = { id: number; name: string; archived: boolean; uses: Array<'from' | 'reply_to'> };

export type SendingDomain = {
    id: number;
    domain: string;
    label: string | null;
    status: SendingDomainStatus;
    mailgun_domain: string;
    mailgun_domain_override: string | null;
    last_check: LastCheck | null;
    last_checked_at: string | null;
    verified_at: string | null;
    last_test: TestResult | null;
    created_by: string | null;
    in_use: NewsletterUse[];
    addresses: SenderAddress[];
};

type TestContext = { purposes: PurposeOption[]; testRecipient: string; offerTestAddressId: number | null };

function formatTime(iso: string | null): string {
    if (!iso) return 'Never';
    return new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

function statusLabel(status: SendingDomainStatus): string {
    switch (status) {
        case 'pending':
            return 'Pending';
        case 'verified':
            return 'Verified';
        case 'failed':
            return 'Failed';
        case 'disabled':
            return 'Disabled';
        default: {
            const exhaustive: never = status;
            return exhaustive;
        }
    }
}

function statusTone(status: SendingDomainStatus): BadgeTone {
    switch (status) {
        case 'pending':
            return 'warning';
        case 'verified':
            return 'ok';
        case 'failed':
            return 'error';
        case 'disabled':
            return 'neutral';
        default: {
            const exhaustive: never = status;
            return exhaustive;
        }
    }
}

function usageLabel(use: NewsletterUse): string {
    const parts = use.uses.map((item) => (item === 'from' ? 'From' : 'Reply-to'));
    return `${use.name}${use.archived ? ' (archived)' : ''}: ${parts.join(' and ')}`;
}

function CopyButton({ value, label }: { value: string; label: string }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        void navigator.clipboard?.writeText(value).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" onClick={copy} aria-label={`Copy ${label}`}>
            {copied ? 'Copied' : 'Copy'}
        </button>
    );
}

function RecordsTable({ check }: { check: LastCheck }) {
    const rows: DnsRecord[] = [
        ...check.records,
        {
            purpose: 'DMARC',
            type: 'TXT',
            name: check.dmarc.name,
            value: check.dmarc.value ?? check.dmarc.recommendation,
            valid: check.dmarc.value !== null,
        },
    ];

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
                <caption className="sr-only">DNS records for {check.mailgun_domain}</caption>
                <thead>
                    <tr className="text-muted">
                        <th scope="col" className="py-1 pr-3 font-semibold">
                            Purpose
                        </th>
                        <th scope="col" className="py-1 pr-3 font-semibold">
                            Type
                        </th>
                        <th scope="col" className="py-1 pr-3 font-semibold">
                            Name and value
                        </th>
                        <th scope="col" className="py-1 font-semibold">
                            Check
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((record, index) => (
                        <tr key={`${record.purpose}-${record.name}-${index}`} className="border-t border-border align-top">
                            <th scope="row" className="py-2 pr-3 font-semibold text-body">
                                {record.purpose}
                            </th>
                            <td className="py-2 pr-3">{record.type}</td>
                            <td className="py-2 pr-3">
                                {record.name !== '' && (
                                    <div className="flex flex-wrap items-center gap-2">
                                        <code className="break-all">{record.name}</code>
                                        <CopyButton value={record.name} label={`${record.purpose} record name`} />
                                    </div>
                                )}
                                <div className="mt-1 flex flex-wrap items-center gap-2">
                                    <code className="break-all text-muted">{record.value}</code>
                                    <CopyButton value={record.value} label={`${record.purpose} record value`} />
                                </div>
                                {record.purpose === 'DMARC' && !record.valid && (
                                    <p className="mt-1 text-xs text-muted">No DMARC record found. The value shown is a recommended starting point.</p>
                                )}
                            </td>
                            <td className="py-2">
                                <Badge tone={record.valid ? 'ok' : 'error'}>{record.valid ? 'Pass' : 'Fail'}</Badge>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function DomainCard({ domain, context }: { domain: SendingDomain; context: TestContext }) {
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState<'disable' | 'remove' | null>(null);
    const headingId = `email-domain-${domain.id}-heading`;
    const options = { preserveScroll: true, onFinish: () => setBusy(false) };
    const inUse = domain.in_use.length > 0;

    const recheck = () => {
        setBusy(true);
        router.post(`/admin/settings/email-domains/${domain.id}/recheck`, {}, options);
    };

    const disable = () => {
        setConfirming(null);
        setBusy(true);
        router.post(`/admin/settings/email-domains/${domain.id}/disable`, {}, options);
    };

    const enable = () => {
        setBusy(true);
        router.post(`/admin/settings/email-domains/${domain.id}/enable`, {}, options);
    };

    const remove = () => {
        setConfirming(null);
        setBusy(true);
        router.delete(`/admin/settings/email-domains/${domain.id}`, options);
    };

    const menuActions: MenuAction[] = [
        ...(domain.status !== 'disabled' ? [{ label: 'Disable domain', icon: Ban, onSelect: () => setConfirming('disable') }] : []),
        { label: 'Remove domain', icon: Trash2, tone: 'danger' as const, disabled: busy || inUse, onSelect: () => setConfirming('remove') },
    ];

    return (
        <section aria-labelledby={headingId} className="sg-card flex flex-col gap-4 p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id={headingId} className="text-base font-semibold text-body">
                        {domain.domain}
                    </h2>
                    {domain.label && <p className="text-sm text-muted">{domain.label}</p>}
                    <p className="text-xs text-muted">
                        Mailgun domain: <span className="font-mono">{domain.mailgun_domain}</span>
                        {domain.verified_at ? ` · Verified ${formatTime(domain.verified_at)}` : ''}
                        {domain.created_by ? ` · Added by ${domain.created_by}` : ''}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Badge tone={statusTone(domain.status)}>{statusLabel(domain.status)}</Badge>
                    <ActionMenu label={`More actions for ${domain.domain}`} actions={menuActions} />
                </div>
            </div>

            {domain.last_check && (
                <p className="text-sm text-body" aria-live="polite">
                    {domain.last_check.message} <span className="text-xs text-muted">Checked {formatTime(domain.last_checked_at)}.</span>
                </p>
            )}

            <div className="text-sm">
                <p className="font-semibold text-body">Used by</p>
                {domain.in_use.length === 0 ? (
                    <p className="text-muted">No newsletters use this domain.</p>
                ) : (
                    <ul className="mt-1 list-disc pl-5 text-body">
                        {domain.in_use.map((use) => (
                            <li key={use.id}>{usageLabel(use)}</li>
                        ))}
                    </ul>
                )}
            </div>

            {domain.last_check && domain.last_check.found && (
                <details className="text-sm">
                    <summary className="min-h-11 cursor-pointer py-2 font-semibold text-body">DNS records</summary>
                    <RecordsTable check={domain.last_check} />
                </details>
            )}

            <SenderAddresses
                domainId={domain.id}
                domain={domain.domain}
                addresses={domain.addresses}
                purposes={context.purposes}
                testRecipient={context.testRecipient}
                offerTestAddressId={context.offerTestAddressId}
            />

            <div className="flex flex-wrap gap-2">
                {domain.status !== 'disabled' && (
                    <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={busy} onClick={recheck}>
                        Check DNS
                    </button>
                )}
                {domain.status === 'disabled' && (
                    <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={busy} onClick={enable}>
                        Enable
                    </button>
                )}
            </div>
            {domain.status !== 'disabled' && (
                <TestSendPanel
                    url={`/admin/settings/email-domains/${domain.id}/test`}
                    subject={domain.domain}
                    recipient={context.testRecipient}
                    lastTest={domain.last_test}
                />
            )}
            {inUse && <p className="text-xs text-muted">A domain in use cannot be removed. Change those newsletters first, or disable the domain.</p>}
            <ConfirmDialog
                open={confirming === 'disable'}
                title={`Disable ${domain.domain}?`}
                description={
                    inUse
                        ? `${domain.in_use.map((use) => use.name).join(', ')} will not be able to send until it is enabled again.`
                        : 'Newsletters cannot use it until it is enabled again.'
                }
                confirmLabel="Disable domain"
                destructive
                processing={busy}
                onCancel={() => setConfirming(null)}
                onConfirm={disable}
            />
            <ConfirmDialog
                open={confirming === 'remove'}
                title={`Remove ${domain.domain}?`}
                description="It is removed from the approved sending domains. This cannot be undone."
                confirmLabel="Remove domain"
                destructive
                processing={busy}
                onCancel={() => setConfirming(null)}
                onConfirm={remove}
            />
        </section>
    );
}

function AddDomainForm() {
    const form = useForm({ domain: '', label: '', mailgun_domain: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/admin/settings/email-domains', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="add-email-domain-heading">
            <h2 id="add-email-domain-heading" className="text-base font-semibold text-body">
                Add a domain
            </h2>
            <p className="text-sm text-muted">
                Add the domain in the Mailgun dashboard first. This page never changes Mailgun: it looks the domain up, shows the DNS records it needs and can
                send test emails through it.
            </p>
            <label htmlFor="email-domain-domain" className="text-sm font-semibold text-body">
                Domain
                <input
                    id="email-domain-domain"
                    className="form-input mt-1 w-full"
                    placeholder="example.org.uk"
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.domain}
                    aria-invalid={form.errors.domain ? true : undefined}
                    aria-describedby={form.errors.domain ? 'email-domain-domain-error' : undefined}
                    onChange={(event) => form.setData('domain', event.target.value)}
                />
            </label>
            {form.errors.domain && (
                <p id="email-domain-domain-error" className="text-sm text-danger">
                    {form.errors.domain}
                </p>
            )}
            <label htmlFor="email-domain-label" className="text-sm font-semibold text-body">
                Label (optional)
                <input id="email-domain-label" className="form-input mt-1 w-full" value={form.data.label} onChange={(event) => form.setData('label', event.target.value)} />
            </label>
            {form.errors.label && <p className="text-sm text-danger">{form.errors.label}</p>}
            <label htmlFor="email-domain-mailgun" className="text-sm font-semibold text-body">
                Mailgun domain (optional)
                <input
                    id="email-domain-mailgun"
                    className="form-input mt-1 w-full"
                    placeholder="mg.example.org.uk"
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.mailgun_domain}
                    aria-describedby="email-domain-mailgun-help"
                    onChange={(event) => form.setData('mailgun_domain', event.target.value)}
                />
            </label>
            <p id="email-domain-mailgun-help" className="text-xs text-muted">
                Only needed when Mailgun sends from a subdomain that is not the configured Mailgun domain.
            </p>
            {form.errors.mailgun_domain && <p className="text-sm text-danger">{form.errors.mailgun_domain}</p>}
            <button type="submit" className="sg-btn sg-btn-primary self-start" disabled={form.processing || form.data.domain.trim() === ''}>
                Add and check
            </button>
        </form>
    );
}

export default function EmailDomainsIndex({
    domains,
    mailgunConfigured,
    configuredMailgunDomain,
    defaultReplyTo = null,
    testRecipient = '',
    offerTestAddressId = null,
    purposes = [],
}: {
    domains: SendingDomain[];
    mailgunConfigured: boolean;
    configuredMailgunDomain: string | null;
    defaultReplyTo?: string | null;
    testRecipient?: string;
    offerTestAddressId?: number | null;
    purposes?: PurposeOption[];
}) {
    const { errors } = usePage<SharedPageProps>().props;
    const removeError = errors?.remove;
    const context: TestContext = { purposes, testRecipient, offerTestAddressId };

    return (
        <WorkspaceLayout
            area="Admin"
            active="email-domains"
            title="Sending domains"
            subtitle="Domains newsletters may send from or use for replies."
            actions={
                <PillButton href="/admin/settings" variant="secondary" icon={Settings}>
                    Back to Settings
                </PillButton>
            }
        >
            <>
                {removeError && (
                    <p role="alert" className="sg-card border-l-4 !border-l-[var(--color-sg-error)] px-4 py-3 text-sm text-danger">
                        {removeError}
                    </p>
                )}
                <section className="sg-card flex flex-col gap-2 p-5 text-sm text-body sm:p-6" aria-labelledby="email-domains-rules-heading">
                    <h2 id="email-domains-rules-heading" className="text-base font-semibold">
                        How these are used
                    </h2>
                    <p>
                        A newsletter From address must be on a domain&apos;s list of From addresses, and the domain must be enabled and verified by Mailgun
                        (active, with SPF, DKIM and DMARC passing). Each newsletter sends through its From domain&apos;s own Mailgun domain. A reply-to address
                        only needs an enabled domain. Sends are re-checked against this list just before they go out. System and account emails always
                        send from apesnews.org.uk.
                    </p>
                    <p className="text-muted">
                        {mailgunConfigured
                            ? `Mailgun is configured${configuredMailgunDomain ? ` with sending domain ${configuredMailgunDomain}` : ''}. Domains are re-checked every day.`
                            : 'The Mailgun API key is not configured under Integrations, so domains cannot be checked and test emails cannot be sent.'}
                    </p>
                </section>
                {domains.map((domain) => (
                    <DomainCard key={domain.id} domain={domain} context={context} />
                ))}
                <DefaultReplyToForm value={defaultReplyTo} />
                <AddDomainForm />
            </>
        </WorkspaceLayout>
    );
}
