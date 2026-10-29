import { Link, router, useForm } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import { useId, useState, type FormEvent } from 'react';
import { refusalMessage, xsrfToken } from '../../../../Components/Admin/EmailDomains/TestSendPanel';
import WorkspaceLayout from '../../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../../Components/Workspace/ui/Badge';
import PillButton from '../../../../Components/Workspace/ui/PillButton';
import { nestByDots, SettingField, type SettingDefinition } from '../Index';

export type TransportKey = 'cloudron_smtp' | 'mailgun_api' | 'custom_smtp';

export type TransportOption = {
    value: TransportKey;
    label: string;
    configured: boolean;
    system_problem: string | null;
    newsletter_problem: string | null;
};

export type TransportTestResult = {
    ok: boolean;
    purpose: 'system' | 'newsletters';
    transport: TransportKey;
    transport_label: string;
    mode: 'connection' | 'send';
    message: string;
    recipients: string[];
    message_id: string | null;
    error: string | null;
    tested_at: string;
};

type Feature = { feature: string } & Record<TransportKey, string>;

type Purpose = TransportTestResult['purpose'];

const PURPOSE_LABELS: Record<Purpose, string> = { system: 'System mail', newsletters: 'Newsletters' };

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

export function isTransportTestResult(value: unknown): value is TransportTestResult {
    if (typeof value !== 'object' || value === null) return false;
    const candidate = value as Partial<TransportTestResult>;
    return typeof candidate.ok === 'boolean' && typeof candidate.message === 'string' && typeof candidate.tested_at === 'string';
}

function TransportTestPanel({ purpose, transportLabel, recipient, lastTest }: { purpose: Purpose; transportLabel: string; recipient: string; lastTest: TransportTestResult | null }) {
    const [extra, setExtra] = useState('');
    const [busy, setBusy] = useState<'connection' | 'send' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<TransportTestResult | null>(lastTest);
    const extraId = useId();
    const headingId = useId();

    const run = async (mode: 'connection' | 'send', event?: FormEvent) => {
        event?.preventDefault();
        setBusy(mode);
        setError(null);
        try {
            const response = await fetch('/admin/settings/mail-sending/test', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ purpose, mode, extra_recipient: mode === 'send' && extra.trim() !== '' ? extra.trim() : null }),
            });
            const payload: unknown = await response.json().catch(() => null);
            if (!response.ok) {
                setError(refusalMessage(response.status, payload));
                if (response.status === 423) router.visit('/account/confirm-identity');
                return;
            }
            if (!isTransportTestResult(payload)) {
                setError('The test response could not be read. Reload the page to see the latest result.');
                return;
            }
            setResult(payload);
        } catch {
            setError('The test could not be run. Check your connection and try again.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3 rounded-xl border border-border bg-white/50 p-4">
            <h3 id={headingId} className="text-sm font-semibold text-body">
                {PURPOSE_LABELS[purpose]} through {transportLabel}
            </h3>
            <form onSubmit={(event) => void run('send', event)} className="flex flex-col gap-2">
                <label htmlFor={extraId} className="text-sm font-semibold text-body">
                    Also send the test to (optional)
                    <input id={extraId} type="email" className="form-input mt-1 w-full" autoComplete="off" value={extra} onChange={(event) => setExtra(event.target.value)} />
                </label>
                <p className="text-xs text-muted">The test email always goes to you ({recipient}).</p>
                <div className="flex flex-wrap gap-2">
                    <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={busy !== null} onClick={() => void run('connection')}>
                        {busy === 'connection' ? 'Checking…' : 'Check connection'}
                    </button>
                    <button type="submit" className="sg-btn sg-btn-primary sg-btn-sm" disabled={busy !== null}>
                        {busy === 'send' ? 'Sending…' : 'Send test email'}
                    </button>
                </div>
            </form>
            {error && (
                <p role="alert" className="text-sm text-danger">
                    {error}
                </p>
            )}
            <div aria-live="polite" aria-atomic="true">
                {result && (
                    <div className="flex flex-col gap-1 text-sm">
                        <p className={result.ok ? 'font-semibold text-success' : 'font-semibold text-danger'}>
                            {result.message}
                            {result.message_id ? ` Message ID: ${result.message_id}` : ''}
                        </p>
                        {result.error && <p className="text-danger">{result.error}</p>}
                        <p className="text-xs text-muted">
                            {result.mode === 'connection' ? 'Connection check' : 'Test email'} through {result.transport_label}, {formatTime(result.tested_at)}.
                        </p>
                    </div>
                )}
            </div>
        </section>
    );
}

function SettingsForm({ settings }: { settings: SettingDefinition[] }) {
    const form = useForm<Record<string, SettingDefinition['value']>>(Object.fromEntries(settings.map((setting) => [setting.key, setting.value])));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({ settings: nestByDots(values) }));
        form.put('/admin/settings/mail-sending', { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <form onSubmit={submit} className="sg-card flex flex-col gap-6 p-5 sm:p-6" aria-labelledby="mail-sending-settings-heading">
            <h2 id="mail-sending-settings-heading" className="text-base font-semibold text-body">
                Sending method
            </h2>
            {settings.map((setting) => (
                <SettingField
                    key={setting.key}
                    setting={setting}
                    value={form.data[setting.key] ?? setting.value}
                    error={form.errors[`settings.${setting.key}`]}
                    onChange={(value) => form.setData(setting.key, value)}
                />
            ))}
            <button type="submit" className="sg-btn sg-btn-primary self-start" disabled={form.processing || !form.isDirty}>
                Save mail sending
            </button>
        </form>
    );
}

function displayValue(setting: SettingDefinition): string {
    switch (setting.type) {
        case 'enum':
            return setting.options.find((option) => option.value === setting.value)?.label ?? setting.value;
        case 'int':
            return `${setting.value}${setting.unit ? ` ${setting.unit}` : ''}`;
        case 'bool':
            return setting.value ? 'On' : 'Off';
        case 'multi_enum':
            return setting.value.join(', ') || 'None';
        default: {
            const exhaustive: never = setting;
            return exhaustive;
        }
    }
}

function ReadOnlySettings({ settings }: { settings: SettingDefinition[] }) {
    return (
        <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="mail-sending-current-heading">
            <h2 id="mail-sending-current-heading" className="text-base font-semibold text-body">
                Sending method
            </h2>
            <p className="text-sm text-muted">Only super admins can change these. You can still check connections and send test emails.</p>
            <dl className="grid gap-3 text-sm sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]">
                {settings.map((setting) => (
                    <div key={setting.key} className="contents">
                        <dt className="font-semibold text-body">{setting.label}</dt>
                        <dd className="text-body">{displayValue(setting)}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

export default function MailSendingIndex({
    settings,
    transports,
    effectiveNewsletterTransport,
    cloudron,
    features,
    lastTests,
    canManage,
    testRecipient,
}: {
    settings: SettingDefinition[];
    transports: TransportOption[];
    effectiveNewsletterTransport: TransportKey;
    cloudron: { detected: boolean; mailer: string; from: string | null };
    features: Feature[];
    lastTests: Record<Purpose, TransportTestResult | null>;
    canManage: boolean;
    testRecipient: string;
}) {
    const labelFor = (key: string) => transports.find((transport) => transport.value === key)?.label ?? key;
    const systemSetting = settings.find((setting) => setting.key === 'mail.system_transport');
    const systemTransport = systemSetting?.type === 'enum' ? systemSetting.value : 'cloudron_smtp';

    return (
        <WorkspaceLayout
            area="Admin"
            active="mail-sending"
            title="Mail sending"
            subtitle="How system mail and newsletters leave the Newsroom."
            actions={
                <PillButton href="/admin/settings" variant="secondary" icon={Settings}>
                    Back to Settings
                </PillButton>
            }
        >
            <>
                <section className="sg-card flex flex-col gap-2 p-5 text-sm text-body sm:p-6" aria-labelledby="mail-sending-summary-heading">
                    <h2 id="mail-sending-summary-heading" className="text-base font-semibold">
                        Right now
                    </h2>
                    <p>
                        System and account mail goes through <strong>{labelFor(systemTransport)}</strong>. Newsletters go through{' '}
                        <strong>{labelFor(effectiveNewsletterTransport)}</strong>. Newsletters never switch transport: if theirs fails, the send pauses and
                        admins are emailed.
                    </p>
                    <p className="text-muted">
                        {cloudron.detected
                            ? `Cloudron's mail addon is connected (mailer ${cloudron.mailer}, From ${cloudron.from ?? 'not set'}). It stays required for Cloudron SMTP.`
                            : `Cloudron's mail addon is not detected; Cloudron SMTP uses this environment's ${cloudron.mailer} mailer.`}
                    </p>
                    <p className="text-muted">
                        Keys for Mailgun and Custom SMTP are set under{' '}
                        <Link href="/admin/settings/integrations" className="font-semibold underline">
                            Integrations &amp; keys
                        </Link>
                        ; From addresses and Mailgun domains under{' '}
                        <Link href="/admin/settings/email-domains" className="font-semibold underline">
                            Sending domains
                        </Link>
                        .
                    </p>
                </section>

                {canManage ? <SettingsForm settings={settings} /> : <ReadOnlySettings settings={settings} />}

                <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="mail-sending-transports-heading">
                    <h2 id="mail-sending-transports-heading" className="text-base font-semibold text-body">
                        Transports
                    </h2>
                    <ul className="flex flex-col gap-2 text-sm">
                        {transports.map((transport) => (
                            <li key={transport.value} className="flex flex-wrap items-center gap-2">
                                <span className="font-semibold text-body">{transport.label}</span>
                                <Badge tone={transport.configured ? 'ok' : 'neutral'}>{transport.configured ? 'Ready' : 'Not set up'}</Badge>
                                {transport.newsletter_problem && transport.newsletter_problem !== transport.system_problem && (
                                    <span className="text-muted">Newsletters: {transport.newsletter_problem}</span>
                                )}
                                {transport.system_problem && <span className="text-muted">{transport.system_problem}</span>}
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="mail-sending-features-heading">
                    <h2 id="mail-sending-features-heading" className="text-base font-semibold text-body">
                        What each transport supports
                    </h2>
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Features available on each mail transport</caption>
                            <thead>
                                <tr className="text-muted">
                                    <th scope="col" className="py-1 pr-3 font-semibold">
                                        Feature
                                    </th>
                                    {transports.map((transport) => (
                                        <th key={transport.value} scope="col" className="py-1 pr-3 font-semibold">
                                            {transport.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {features.map((feature) => (
                                    <tr key={feature.feature} className="border-t border-border align-top">
                                        <th scope="row" className="py-2 pr-3 font-semibold text-body">
                                            {feature.feature}
                                        </th>
                                        {transports.map((transport) => (
                                            <td key={transport.value} className="py-2 pr-3 text-body">
                                                {feature[transport.value]}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="mail-sending-tests-heading">
                    <h2 id="mail-sending-tests-heading" className="text-base font-semibold text-body">
                        Test the current setup
                    </h2>
                    <p className="text-sm text-muted">Check connection signs in without sending. Send test email sends one real email straight away.</p>
                    <div className="grid gap-3 md:grid-cols-2">
                        <TransportTestPanel purpose="system" transportLabel={labelFor(systemTransport)} recipient={testRecipient} lastTest={lastTests.system} />
                        <TransportTestPanel
                            purpose="newsletters"
                            transportLabel={labelFor(effectiveNewsletterTransport)}
                            recipient={testRecipient}
                            lastTest={lastTests.newsletters}
                        />
                    </div>
                </section>
            </>
        </WorkspaceLayout>
    );
}
