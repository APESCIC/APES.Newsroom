import { useForm } from '@inertiajs/react';
import { KeySquare } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import ReasonConfirmDialog from '../../../Components/Admin/ReasonConfirmDialog';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import DataTable from '../../../Components/Workspace/ui/DataTable';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';

export type ContentApiKeyRow = {
    id: number;
    name: string;
    prefix: string;
    created_by: string | null;
    created_at: string | null;
    last_used_at: string | null;
    revoked_at: string | null;
};

function formatWhen(iso: string | null, fallback = '—') {
    if (!iso) return fallback;
    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeZone: 'Europe/London' }).format(new Date(iso));
}

export default function Index({
    keys,
    environmentKeyConfigured,
    created,
}: {
    keys: ContentApiKeyRow[];
    environmentKeyConfigured: boolean;
    created: { id: number; name: string; key: string } | null;
}) {
    const createForm = useForm({ name: '' });
    const revokeForm = useForm({});
    const [revoking, setRevoking] = useState<ContentApiKeyRow | null>(null);

    return (
        <WorkspaceLayout area="Admin" active="content-api-keys" title="Content API keys" subtitle="Keys for headless reads of published content">
            <>
                {created && (
                    <section className="sg-card flex flex-col gap-2 border-l-4 !border-l-[var(--color-sg-accent)] p-4" aria-label="New key">
                        <p className="font-semibold text-body">Key "{created.name}" created. Copy it now: it will not be shown again.</p>
                        <code className="rounded-xl bg-white px-3 py-2 font-mono text-sm break-all text-body">{created.key}</code>
                    </section>
                )}
                {environmentKeyConfigured && (
                    <p className="text-sm text-muted">
                        The <code>NEWSROOM_CONTENT_API_KEY</code> environment key is still accepted. Move integrations to named keys, then remove it from the
                        environment.
                    </p>
                )}

                <form
                    aria-label="Create a key"
                    className="sg-card flex flex-col gap-3 p-4 sm:flex-row sm:items-end"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        createForm.post('/admin/content-api-keys', { preserveScroll: true, onSuccess: () => createForm.reset() });
                    }}
                >
                    <label className="flex-1 text-sm font-semibold text-body">
                        Key name
                        <input
                            className="form-input mt-1.5"
                            value={createForm.data.name}
                            onChange={(event) => createForm.setData('name', event.target.value)}
                            placeholder="Partner website"
                            required
                            maxLength={120}
                        />
                    </label>
                    <button type="submit" className="sg-btn sg-btn-primary" disabled={createForm.processing}>
                        Create key
                    </button>
                </form>
                {createForm.errors.name && <p className="text-sm text-danger">{createForm.errors.name}</p>}

                {keys.length === 0 ? (
                    <EmptyState icon={KeySquare} title="No named Content API keys yet." />
                ) : (
                    <DataTable
                        label="Content API keys"
                        rows={keys}
                        rowKey={(key) => key.id}
                        columns={[
                            { key: 'name', header: 'Name', primary: true, cell: (key) => <span className="font-semibold text-body">{key.name}</span> },
                            { key: 'prefix', header: 'Key starts with', cell: (key) => <span className="font-mono text-xs">{key.prefix}…</span> },
                            {
                                key: 'created',
                                header: 'Created',
                                cell: (key) => `${formatWhen(key.created_at)}${key.created_by ? ` by ${key.created_by}` : ''}`,
                            },
                            { key: 'last-used', header: 'Last used', cell: (key) => formatWhen(key.last_used_at, 'Never') },
                            {
                                key: 'status',
                                header: 'Status',
                                cell: (key) => (key.revoked_at ? <Badge tone="error">Revoked</Badge> : <Badge tone="ok">Active</Badge>),
                            },
                            {
                                key: 'actions',
                                header: <span className="sr-only">Actions</span>,
                                label: 'Actions',
                                cell: (key) =>
                                    !key.revoked_at && (
                                        <button type="button" className="sg-btn sg-btn-danger sg-btn-sm" onClick={() => setRevoking(key)}>
                                            Revoke
                                        </button>
                                    ),
                            },
                        ]}
                    />
                )}
            </>
            <ReasonConfirmDialog
                open={revoking !== null}
                title={`Revoke "${revoking?.name ?? ''}"?`}
                description="Requests using this key will be refused immediately. This cannot be undone."
                confirmLabel="Revoke key"
                destructive
                processing={revokeForm.processing}
                onCancel={() => setRevoking(null)}
                onConfirm={(reason) => {
                    if (!revoking) return;
                    revokeForm.transform(() => ({ reason }));
                    revokeForm.delete(`/admin/content-api-keys/${revoking.id}`, { preserveScroll: true, onSuccess: () => setRevoking(null) });
                }}
            />
        </WorkspaceLayout>
    );
}
