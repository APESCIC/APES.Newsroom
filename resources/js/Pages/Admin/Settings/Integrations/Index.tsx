import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import WorkspaceLayout from '../../../../Components/Layout/WorkspaceLayout';
import Badge, { type BadgeTone } from '../../../../Components/Workspace/ui/Badge';
import ConfirmDialog from '../../../../Components/Workspace/ui/ConfirmDialog';
import PillButton from '../../../../Components/Workspace/ui/PillButton';
import type { SharedPageProps } from '../../../../types/page';

export type CheckStatus = 'pass' | 'warn' | 'fail';

export type CheckTrigger = 'schedule' | 'manual' | 'pre_save';

export type CheckResult = {
    id: number;
    status: CheckStatus;
    message: string;
    duration_ms: number;
    trigger: CheckTrigger;
    checked_at: string;
    user: string | null;
};

export type KeySource = 'database' | 'environment' | null;

export type IntegrationKey = {
    key: string;
    label: string;
    help: string;
    sensitive: boolean;
    source: KeySource;
    last_four: string | null;
    value: string | null;
    set_at: string | null;
    set_by: string | null;
    rotated_at: string | null;
};

export type Rotation = {
    id: number;
    provider: string;
    action: 'set' | 'replaced' | 'removed';
    key_label: string;
    last_four: string | null;
    actor: string | null;
    at: string | null;
};

export type IntegrationProvider = {
    provider: string;
    label: string;
    description: string;
    managed: boolean;
    configured: boolean;
    keys: IntegrationKey[];
    latest: CheckResult | null;
    history: CheckResult[];
    rotations: Rotation[];
};

type FailedCheck = { provider: string; key: string } | null;

function formatTime(iso: string | null): string {
    if (!iso) return 'Never';
    return new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

function statusLabel(status: CheckStatus): string {
    switch (status) {
        case 'pass':
            return 'Passing';
        case 'warn':
            return 'Warning';
        case 'fail':
            return 'Failing';
        default: {
            const exhaustive: never = status;
            return exhaustive;
        }
    }
}

function statusTone(status: CheckStatus): BadgeTone {
    switch (status) {
        case 'pass':
            return 'ok';
        case 'warn':
            return 'warning';
        case 'fail':
            return 'error';
        default: {
            const exhaustive: never = status;
            return exhaustive;
        }
    }
}

function triggerLabel(trigger: CheckTrigger): string {
    switch (trigger) {
        case 'schedule':
            return 'Scheduled';
        case 'manual':
            return 'Test connection';
        case 'pre_save':
            return 'Before save';
        default: {
            const exhaustive: never = trigger;
            return exhaustive;
        }
    }
}

function sourceLabel(source: KeySource): string {
    switch (source) {
        case 'database':
            return 'Set in admin';
        case 'environment':
            return 'From server environment';
        case null:
            return 'Not set';
        default: {
            const exhaustive: never = source;
            return exhaustive;
        }
    }
}

function rotationLabel(action: Rotation['action']): string {
    switch (action) {
        case 'set':
            return 'Set';
        case 'replaced':
            return 'Replaced';
        case 'removed':
            return 'Removed';
        default: {
            const exhaustive: never = action;
            return exhaustive;
        }
    }
}

function StatusBadge({ result }: { result: CheckResult | null }) {
    if (!result) {
        return <Badge tone="neutral">Not checked</Badge>;
    }

    return <Badge tone={statusTone(result.status)}>{statusLabel(result.status)}</Badge>;
}

function currentValue(key: IntegrationKey): string | null {
    if (key.source === null) return null;
    if (!key.sensitive) return key.value ?? '';
    return key.last_four ? `••••${key.last_four}` : 'Set';
}

/** Lower-cases a label for use mid-sentence, keeping acronyms such as "API key". */
function phrase(label: string): string {
    return /^[A-Z]{2}/.test(label) ? label : label.charAt(0).toLowerCase() + label.slice(1);
}

function KeyRow({
    provider,
    item,
    canManage,
    failedCheck,
}: {
    provider: IntegrationProvider;
    item: IntegrationKey;
    canManage: boolean;
    failedCheck: FailedCheck;
}) {
    const form = useForm({ secret_value: '', force: false });
    const [confirmRemove, setConfirmRemove] = useState(false);
    const inputId = `integration-${provider.provider}-${item.key}`;
    const errorId = `${inputId}-error`;
    const showForce = Boolean(form.errors.secret_value) && failedCheck?.provider === provider.provider && failedCheck.key === item.key;
    const value = currentValue(item);
    const name = phrase(item.label);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(`/admin/settings/integrations/${provider.provider}/${item.key}`, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    const remove = () => {
        setConfirmRemove(false);
        form.delete(`/admin/settings/integrations/${provider.provider}/${item.key}`, { preserveScroll: true });
    };

    return (
        <div className="flex flex-col gap-2 border-t border-border pt-4 first:border-t-0 first:pt-0">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-sm font-semibold text-body">{item.label}</p>
                <p className="text-xs text-muted">
                    {value !== null && (
                        <>
                            <span className="font-mono">{value}</span> ·{' '}
                        </>
                    )}
                    {sourceLabel(item.source)}
                    {item.set_at && (
                        <>
                            {' '}
                            · {item.rotated_at ? 'Replaced' : 'Set'} {formatTime(item.rotated_at ?? item.set_at)}
                            {item.set_by ? ` by ${item.set_by}` : ''}
                        </>
                    )}
                </p>
            </div>
            {canManage && (
                <form onSubmit={submit} className="flex flex-col gap-2">
                    <label htmlFor={inputId} className="sr-only">
                        New {provider.label} {name}
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <input
                            id={inputId}
                            type={item.sensitive ? 'password' : 'text'}
                            autoComplete="off"
                            spellCheck={false}
                            className="form-input min-w-0 flex-1"
                            placeholder={item.source === null ? `Enter ${name}` : `Replace ${name}`}
                            value={form.data.secret_value}
                            aria-describedby={form.errors.secret_value ? `${inputId}-help ${errorId}` : `${inputId}-help`}
                            aria-invalid={form.errors.secret_value ? true : undefined}
                            onChange={(event) => form.setData('secret_value', event.target.value)}
                        />
                        <button type="submit" className="sg-btn sg-btn-primary" disabled={form.processing || form.data.secret_value === ''}>
                            {item.source === 'database' ? 'Replace' : 'Save'}
                        </button>
                        {item.source === 'database' && (
                            <button type="button" className="sg-btn sg-btn-ghost !text-danger" disabled={form.processing} onClick={() => setConfirmRemove(true)}>
                                Remove
                            </button>
                        )}
                    </div>
                    <p id={`${inputId}-help`} className="text-xs text-muted">
                        {item.help}
                    </p>
                    {form.errors.secret_value && (
                        <p id={errorId} className="text-sm text-danger">
                            {form.errors.secret_value}
                        </p>
                    )}
                    {showForce && (
                        <label className="inline-flex min-h-11 items-center gap-2 text-sm text-body">
                            <input type="checkbox" checked={form.data.force} onChange={(event) => form.setData('force', event.target.checked)} />
                            Save anyway, even though the connection test failed
                        </label>
                    )}
                </form>
            )}
            <ConfirmDialog
                open={confirmRemove}
                title={`Remove the stored ${provider.label} ${name}?`}
                description="The server environment value, if any, will be used instead."
                confirmLabel="Remove key"
                destructive
                processing={form.processing}
                onCancel={() => setConfirmRemove(false)}
                onConfirm={remove}
            />
        </div>
    );
}

function ProviderCard({ provider, canManage, failedCheck }: { provider: IntegrationProvider; canManage: boolean; failedCheck: FailedCheck }) {
    const [testing, setTesting] = useState(false);
    const headingId = `integration-${provider.provider}-heading`;

    const test = () => {
        setTesting(true);
        router.post(`/admin/settings/integrations/${provider.provider}/test`, {}, { preserveScroll: true, onFinish: () => setTesting(false) });
    };

    return (
        <section aria-labelledby={headingId} className="sg-card flex flex-col gap-4 p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id={headingId} className="text-base font-semibold text-body">
                        {provider.label}
                    </h2>
                    <p className="text-sm text-muted">{provider.description}</p>
                </div>
                <div className="flex items-center gap-3">
                    <StatusBadge result={provider.latest} />
                    <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={testing || !provider.configured} onClick={test}>
                        Test connection
                    </button>
                </div>
            </div>
            {provider.latest && (
                <p className="text-sm text-body">
                    {provider.latest.message} <span className="text-xs text-muted">Checked {formatTime(provider.latest.checked_at)}.</span>
                </p>
            )}
            {!provider.managed && <p className="text-sm text-muted">Managed by Cloudron in the server environment; nothing to enter here.</p>}
            {provider.keys.map((item) => (
                <KeyRow key={item.key} provider={provider} item={item} canManage={canManage} failedCheck={failedCheck} />
            ))}
            {(provider.history.length > 0 || provider.rotations.length > 0) && (
                <details className="text-sm">
                    <summary className="min-h-11 cursor-pointer py-2 font-semibold text-body">History</summary>
                    {provider.history.length > 0 && (
                        <>
                            <h3 className="mt-2 font-semibold text-body">Recent checks</h3>
                            <ul className="mt-1 flex flex-col gap-1">
                                {provider.history.map((result) => (
                                    <li key={result.id} className="text-muted">
                                        {formatTime(result.checked_at)} · {statusLabel(result.status)} · {triggerLabel(result.trigger)}
                                        {result.user ? ` by ${result.user}` : ''} · {result.duration_ms} ms — {result.message}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                    {provider.rotations.length > 0 && (
                        <>
                            <h3 className="mt-3 font-semibold text-body">Key changes</h3>
                            <ul className="mt-1 flex flex-col gap-1">
                                {provider.rotations.map((rotation) => (
                                    <li key={rotation.id} className="text-muted">
                                        {formatTime(rotation.at)} · {rotationLabel(rotation.action)} {rotation.key_label}
                                        {rotation.last_four ? ` (••••${rotation.last_four})` : ''}
                                        {rotation.actor ? ` by ${rotation.actor}` : ''}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </details>
            )}
        </section>
    );
}

export default function IntegrationsIndex({
    providers,
    canManage,
    failedCheck = null,
}: {
    providers: IntegrationProvider[];
    canManage: boolean;
    failedCheck?: FailedCheck;
}) {
    const { errors } = usePage<SharedPageProps>().props;
    const otherErrors = Object.entries(errors ?? {}).filter(([field]) => field !== 'secret_value');

    return (
        <WorkspaceLayout
            area="Admin"
            active="integrations"
            title="Integrations"
            subtitle="Service keys and connection health."
            actions={
                <PillButton href="/admin/settings" variant="secondary" icon={Settings}>
                    Back to Settings
                </PillButton>
            }
        >
            <Head title="Integrations" />
            <>
                {otherErrors.length > 0 && (
                    <div role="alert" className="sg-card border-l-4 !border-l-[var(--color-sg-error)] px-4 py-3 text-sm text-danger">
                        {otherErrors.map(([field, message]) => (
                            <p key={field}>{message}</p>
                        ))}
                    </div>
                )}
                <section aria-labelledby="integrations-overview-heading" className="sg-card flex flex-col gap-3 py-5">
                    <h2 id="integrations-overview-heading" className="px-5 text-base font-semibold text-body">
                        Status overview
                    </h2>
                    <table className="sg-table text-sm">
                        <thead>
                            <tr>
                                <th scope="col">Integration</th>
                                <th scope="col">Status</th>
                                <th scope="col">Last checked</th>
                            </tr>
                        </thead>
                        <tbody>
                            {providers.map((provider) => (
                                <tr key={provider.provider}>
                                    <th scope="row" className="!normal-case !tracking-normal text-sm !text-body">
                                        {provider.label}
                                    </th>
                                    <td data-label="Status">{provider.configured ? <StatusBadge result={provider.latest} /> : <span className="text-muted">Not configured</span>}</td>
                                    <td data-label="Last checked" className="text-muted">
                                        {formatTime(provider.latest?.checked_at ?? null)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <p className="px-5 text-xs text-muted">Configured integrations are checked every hour. Admins are emailed when a check starts failing.</p>
                </section>
                {!canManage && (
                    <p className="text-sm text-muted">You can view status and test connections. Only super admins can change keys.</p>
                )}
                {canManage && (
                    <p className="text-sm text-muted">
                        Saved keys are encrypted and never shown again; only the last four characters are displayed. Changing a key asks you to confirm your
                        identity if you have not done so in the last five minutes.
                    </p>
                )}
                {providers.map((provider) => (
                    <ProviderCard key={provider.provider} provider={provider} canManage={canManage} failedCheck={failedCheck} />
                ))}
            </>
        </WorkspaceLayout>
    );
}
