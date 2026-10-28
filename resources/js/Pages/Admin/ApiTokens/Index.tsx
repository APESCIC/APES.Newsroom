import { useForm, usePage } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import ReasonConfirmDialog from '../../../Components/Admin/ReasonConfirmDialog';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import type { SharedPageProps } from '../../../types/page';

type Person = { id: number; name: string; email: string };

export type TokenStatus = 'active' | 'expired' | 'revoked';

export type ApiTokenRow = {
    id: number;
    name: string;
    owner: Person | null;
    abilities: string[] | null;
    status: TokenStatus;
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
    revoked_at: string | null;
};

type Option = { value: string; label: string };

function formatWhen(iso: string | null, fallback = '—') {
    if (!iso) return fallback;
    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'Europe/London' }).format(new Date(iso));
}

function statusLabel(status: TokenStatus): string {
    switch (status) {
        case 'active':
            return 'Active';
        case 'expired':
            return 'Expired';
        case 'revoked':
            return 'Revoked';
        default: {
            const unreachable: never = status;
            return unreachable;
        }
    }
}

function CreateForm({ owners, abilities, expiryDays, defaultOwnerId }: { owners: Person[]; abilities: Option[]; expiryDays: number[]; defaultOwnerId: number | null }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        owner_id: defaultOwnerId ?? owners[0]?.id ?? 0,
        abilities: abilities.map((ability) => ability.value),
        expires_in_days: expiryDays.includes(90) ? 90 : expiryDays[0],
    });

    function toggle(value: string) {
        setData('abilities', data.abilities.includes(value) ? data.abilities.filter((item) => item !== value) : [...data.abilities, value]);
    }

    return (
        <form
            aria-label="Create a token"
            className="sg-card flex flex-col gap-3 p-5 sm:p-6"
            onSubmit={(event: FormEvent) => {
                event.preventDefault();
                post('/admin/api-tokens', { preserveScroll: true, onSuccess: () => reset('name') });
            }}
        >
            <h2 className="text-base font-semibold text-body">Create a token</h2>
            <label className="text-sm font-bold text-body">
                Name
                <input className="form-input mt-1" value={data.name} onChange={(event) => setData('name', event.target.value)} required maxLength={120} />
            </label>
            {errors.name && <p className="text-sm text-danger">{errors.name}</p>}
            <label className="text-sm font-bold text-body">
                Owner
                <select className="form-input mt-1" value={data.owner_id} onChange={(event) => setData('owner_id', Number(event.target.value))}>
                    {owners.map((owner) => (
                        <option key={owner.id} value={owner.id}>
                            {owner.name} ({owner.email})
                        </option>
                    ))}
                </select>
            </label>
            {errors.owner_id && <p className="text-sm text-danger">{errors.owner_id}</p>}
            <fieldset>
                <legend className="text-sm font-bold text-body">Abilities</legend>
                <div className="mt-2 flex flex-col gap-1">
                    {abilities.map((ability) => (
                        <label key={ability.value} className="flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" checked={data.abilities.includes(ability.value)} onChange={() => toggle(ability.value)} />
                            {ability.label} <span className="font-mono text-xs text-muted">{ability.value}</span>
                        </label>
                    ))}
                </div>
            </fieldset>
            {errors.abilities && <p className="text-sm text-danger">{errors.abilities}</p>}
            <label className="text-sm font-bold text-body">
                Expires after
                <select className="form-input mt-1" value={data.expires_in_days} onChange={(event) => setData('expires_in_days', Number(event.target.value))}>
                    {expiryDays.map((days) => (
                        <option key={days} value={days}>
                            {days} days
                        </option>
                    ))}
                </select>
            </label>
            <button type="submit" className="sg-btn sg-btn-primary self-start" disabled={processing || data.abilities.length === 0}>
                Create token
            </button>
        </form>
    );
}

export default function Index({
    tokens,
    owners,
    abilities,
    expiryDays,
    created,
}: {
    tokens: ApiTokenRow[];
    owners: Person[];
    abilities: Option[];
    expiryDays: number[];
    created: { id: number; name: string; token: string } | null;
}) {
    const { auth } = usePage<SharedPageProps>().props;
    const [revoking, setRevoking] = useState<ApiTokenRow | null>(null);
    const revokeForm = useForm({});

    return (
        <WorkspaceLayout area="Admin" active="api-tokens" title="API tokens" subtitle="Bearer tokens for the Admin API">
            {created && (
                <section className="sg-card flex flex-col gap-2 border-l-4 !border-l-[var(--color-sg-accent)] p-4" aria-label="New token">
                    <p className="font-semibold text-body">Token "{created.name}" created. Copy it now: it will not be shown again.</p>
                    <code className="rounded-xl bg-white px-3 py-2 font-mono text-sm break-all text-body">{created.token}</code>
                </section>
            )}

            <CreateForm owners={owners} abilities={abilities} expiryDays={expiryDays} defaultOwnerId={auth.user?.id ?? null} />

            {tokens.length === 0 ? (
                <EmptyState icon={KeyRound} title="No Admin API tokens yet." description="Create a token above to let a service call the Admin API." />
            ) : (
                <DataTable
                    label="Admin API tokens"
                    rows={tokens}
                    rowKey={(token) => token.id}
                    columns={[
                        { key: 'name', header: 'Name', primary: true, cell: (token) => <span className="font-semibold text-body">{token.name}</span> },
                        { key: 'owner', header: 'Owner', cell: (token) => (token.owner ? token.owner.name : 'Deleted user') },
                        {
                            key: 'abilities',
                            header: 'Abilities',
                            cell: (token) => <span className="font-mono text-xs">{token.abilities ? token.abilities.join(', ') : 'all'}</span>,
                        },
                        {
                            key: 'status',
                            header: 'Status',
                            cell: (token) => <Badge tone={token.status === 'active' ? 'ok' : 'error'}>{statusLabel(token.status)}</Badge>,
                        },
                        { key: 'created', header: 'Created', cell: (token) => formatWhen(token.created_at) },
                        { key: 'last-used', header: 'Last used', cell: (token) => formatWhen(token.last_used_at, 'Never') },
                        { key: 'expires', header: 'Expires', cell: (token) => formatWhen(token.expires_at, 'Never') },
                        {
                            key: 'actions',
                            header: <span className="sr-only">Actions</span>,
                            label: 'Actions',
                            cell: (token) =>
                                token.status !== 'revoked' && (
                                    <button type="button" className="sg-btn sg-btn-danger sg-btn-sm" onClick={() => setRevoking(token)}>
                                        Revoke
                                    </button>
                                ),
                        },
                    ]}
                />
            )}
            <ReasonConfirmDialog
                open={revoking !== null}
                title={`Revoke "${revoking?.name ?? ''}"?`}
                description="Anything using this token will stop working immediately. This cannot be undone."
                confirmLabel="Revoke token"
                destructive
                processing={revokeForm.processing}
                onCancel={() => setRevoking(null)}
                onConfirm={(reason) => {
                    if (!revoking) return;
                    revokeForm.transform(() => ({ reason }));
                    revokeForm.delete(`/admin/api-tokens/${revoking.id}`, { preserveScroll: true, onSuccess: () => setRevoking(null) });
                }}
            />
        </WorkspaceLayout>
    );
}
