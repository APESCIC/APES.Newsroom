import { Head, useForm, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import ReasonConfirmDialog from '../../../Components/Admin/ReasonConfirmDialog';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import type { SharedPageProps } from '../../../types/page';

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
    const { flash } = usePage<SharedPageProps>().props;
    const createForm = useForm({ name: '' });
    const revokeForm = useForm({});
    const [revoking, setRevoking] = useState<ContentApiKeyRow | null>(null);

    return (
        <WorkspaceLayout area="Admin" active="content-api-keys" title="Content API keys" subtitle="Keys for headless reads of published content">
            <Head title="Content API keys" />
            <div className="mx-auto flex max-w-4xl flex-col gap-6">
                {flash?.status && (
                    <p role="status" className="workspace-glass-card px-4 py-3 text-sm font-semibold text-body">
                        {flash.status}
                    </p>
                )}
                {created && (
                    <section className="workspace-glass-card flex flex-col gap-2 p-4" aria-label="New key">
                        <p className="font-semibold text-body">Key "{created.name}" created. Copy it now: it will not be shown again.</p>
                        <code className="break-all rounded-control bg-white px-3 py-2 font-mono text-sm text-body">{created.key}</code>
                    </section>
                )}
                {environmentKeyConfigured && (
                    <p className="text-sm text-muted">
                        The <code>NEWSROOM_CONTENT_API_KEY</code> environment key is still accepted. Move integrations to named keys, then remove it from the
                        environment.
                    </p>
                )}

                <form
                    className="glass-form-panel flex flex-col gap-3 sm:flex-row sm:items-end"
                    onSubmit={(event: FormEvent) => {
                        event.preventDefault();
                        createForm.post('/admin/content-api-keys', { preserveScroll: true, onSuccess: () => createForm.reset() });
                    }}
                >
                    <label className="flex-1 text-sm font-bold text-body">
                        Key name
                        <input
                            className="form-input mt-1"
                            value={createForm.data.name}
                            onChange={(event) => createForm.setData('name', event.target.value)}
                            placeholder="Partner website"
                            required
                            maxLength={120}
                        />
                    </label>
                    <button type="submit" className="button-primary w-fit" disabled={createForm.processing}>
                        Create key
                    </button>
                </form>
                {createForm.errors.name && <p className="text-sm text-danger">{createForm.errors.name}</p>}

                {keys.length === 0 ? (
                    <p className="workspace-glass-card p-8 text-center text-muted">No named Content API keys yet.</p>
                ) : (
                    <div className="workspace-glass-card overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted">
                                <tr>
                                    <th scope="col" className="px-4 py-3">Name</th>
                                    <th scope="col" className="px-4 py-3">Key starts with</th>
                                    <th scope="col" className="px-4 py-3">Created</th>
                                    <th scope="col" className="px-4 py-3">Last used</th>
                                    <th scope="col" className="px-4 py-3">Status</th>
                                    <th scope="col" className="px-4 py-3">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {keys.map((key) => (
                                    <tr key={key.id} className="border-t border-border">
                                        <td className="px-4 py-3 font-semibold text-body">{key.name}</td>
                                        <td className="px-4 py-3 font-mono text-xs text-body">{key.prefix}…</td>
                                        <td className="px-4 py-3 text-body">
                                            {formatWhen(key.created_at)}
                                            {key.created_by ? ` by ${key.created_by}` : ''}
                                        </td>
                                        <td className="px-4 py-3 text-body">{formatWhen(key.last_used_at, 'Never')}</td>
                                        <td className={`px-4 py-3 font-semibold ${key.revoked_at ? 'text-danger' : 'text-body'}`}>{key.revoked_at ? 'Revoked' : 'Active'}</td>
                                        <td className="px-4 py-3">
                                            {!key.revoked_at && (
                                                <button type="button" className="button-danger" onClick={() => setRevoking(key)}>
                                                    Revoke
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
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
