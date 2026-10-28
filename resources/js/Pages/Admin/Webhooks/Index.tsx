import { useForm } from '@inertiajs/react';
import { Trash2, Webhook } from 'lucide-react';
import { FormEvent, useState } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import ActionMenu from '../../../Components/Workspace/ui/ActionMenu';
import AdvancedDetails from '../../../Components/Workspace/ui/AdvancedDetails';
import Badge from '../../../Components/Workspace/ui/Badge';
import ConfirmDialog from '../../../Components/Workspace/ui/ConfirmDialog';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';

type Endpoint = {
    id: number;
    name: string;
    url: string;
    events: string[];
    is_active: boolean;
    secret_hint: string;
};

function EventChecklist({ availableEvents, selected, onToggle }: { availableEvents: string[]; selected: string[]; onToggle: (event: string) => void }) {
    return (
        <fieldset>
            <legend className="text-sm font-semibold text-body">Events</legend>
            <div className="mt-2 grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                {availableEvents.map((event) => (
                    <label key={event} className="flex items-center gap-2 text-sm text-body">
                        <input type="checkbox" checked={selected.includes(event)} onChange={() => onToggle(event)} />
                        <span className="font-mono text-xs">{event}</span>
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

function toggled(list: string[], event: string): string[] {
    return list.includes(event) ? list.filter((e) => e !== event) : [...list, event];
}

function CreateForm({ availableEvents }: { availableEvents: string[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        url: '',
        events: availableEvents,
        is_active: true,
    });

    return (
        <form
            aria-label="Add endpoint"
            className="sg-card flex flex-col gap-4 p-5 sm:p-6"
            onSubmit={(e: FormEvent) => {
                e.preventDefault();
                post('/admin/webhooks', { onSuccess: () => reset() });
            }}
        >
            <h2 className="text-base font-semibold text-body">Add endpoint</h2>
            <div className="grid gap-4 md:grid-cols-2">
                <label className="text-sm font-semibold text-body">
                    Name
                    <input className="form-input mt-1.5" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    {errors.name && <span className="mt-1 block text-sm text-danger">{errors.name}</span>}
                </label>
                <label className="text-sm font-semibold text-body">
                    URL
                    <input className="form-input mt-1.5" value={data.url} onChange={(e) => setData('url', e.target.value)} required />
                    {errors.url && <span className="mt-1 block text-sm text-danger">{errors.url}</span>}
                </label>
            </div>
            <EventChecklist availableEvents={availableEvents} selected={data.events} onToggle={(event) => setData('events', toggled(data.events, event))} />
            <button type="submit" className="sg-btn sg-btn-primary self-start" disabled={processing}>
                Create endpoint
            </button>
        </form>
    );
}

function EndpointCard({ endpoint, availableEvents }: { endpoint: Endpoint; availableEvents: string[] }) {
    const [confirmDelete, setConfirmDelete] = useState(false);
    const {
        data,
        setData,
        patch,
        processing,
        delete: destroy,
    } = useForm({
        name: endpoint.name,
        url: endpoint.url,
        events: endpoint.events,
        is_active: endpoint.is_active,
        rotate_secret: false,
    });

    return (
        <form
            aria-label={`Endpoint ${endpoint.name}`}
            className="sg-card flex flex-col gap-4 p-5 sm:p-6"
            onSubmit={(e: FormEvent) => {
                e.preventDefault();
                patch(`/admin/webhooks/${endpoint.id}`);
            }}
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-base font-semibold text-body">{endpoint.name}</h2>
                    {endpoint.is_active ? <Badge tone="ok">Active</Badge> : <Badge tone="neutral">Paused</Badge>}
                    <span className="text-xs text-muted">Secret {endpoint.secret_hint}</span>
                </div>
                <ActionMenu
                    label={`More actions for ${endpoint.name}`}
                    actions={[{ label: 'Delete endpoint', icon: Trash2, tone: 'danger', onSelect: () => setConfirmDelete(true) }]}
                />
            </div>
            <div className="grid gap-4 md:grid-cols-2">
                <label className="text-sm font-semibold text-body">
                    Name
                    <input className="form-input mt-1.5" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                </label>
                <label className="text-sm font-semibold text-body">
                    URL
                    <input className="form-input mt-1.5" value={data.url} onChange={(e) => setData('url', e.target.value)} />
                </label>
            </div>
            <EventChecklist availableEvents={availableEvents} selected={data.events} onToggle={(event) => setData('events', toggled(data.events, event))} />
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                <label className="flex items-center gap-2 text-sm text-body">
                    <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                    Active
                </label>
                <label className="flex items-center gap-2 text-sm text-body">
                    <input type="checkbox" checked={data.rotate_secret} onChange={(e) => setData('rotate_secret', e.target.checked)} />
                    Rotate signing secret on save
                </label>
            </div>
            <button type="submit" className="sg-btn sg-btn-primary self-start" disabled={processing}>
                Save
            </button>
            <ConfirmDialog
                open={confirmDelete}
                title={`Delete "${endpoint.name}"?`}
                description="Deliveries to this URL stop straight away. This cannot be undone."
                confirmLabel="Delete endpoint"
                destructive
                processing={processing}
                onCancel={() => setConfirmDelete(false)}
                onConfirm={() => {
                    setConfirmDelete(false);
                    destroy(`/admin/webhooks/${endpoint.id}`);
                }}
            />
        </form>
    );
}

export default function Index({
    endpoints,
    availableEvents,
    revealedSecret = null,
}: {
    endpoints: Endpoint[];
    availableEvents: string[];
    revealedSecret?: { id: number; name: string; secret: string } | null;
}) {
    return (
        <WorkspaceLayout area="Admin" active="webhooks" title="Webhooks" subtitle="Notify other services when posts are published or memberships change.">
            <AdvancedDetails summary="Signing and delivery">
                <p>
                    Each request is signed with HMAC-SHA256 using the endpoint&apos;s signing secret. Delivery is queued with retries and never blocks publish
                    or membership flows.
                </p>
            </AdvancedDetails>
            {revealedSecret && (
                <section className="sg-card flex flex-col gap-2 border-l-4 !border-l-[var(--color-sg-accent)] p-4" aria-label="Signing secret">
                    <p className="font-semibold text-body">
                        Signing secret for "{revealedSecret.name}". Copy it into the receiving service now: it will not be shown again.
                    </p>
                    <code className="rounded-xl bg-white px-3 py-2 font-mono text-sm break-all text-body">{revealedSecret.secret}</code>
                </section>
            )}
            <CreateForm availableEvents={availableEvents} />
            {endpoints.length === 0 ? (
                <EmptyState icon={Webhook} title="No endpoints yet" description="Add an endpoint above to receive signed event notifications." />
            ) : (
                endpoints.map((endpoint) => <EndpointCard key={endpoint.id} endpoint={endpoint} availableEvents={availableEvents} />)
            )}
        </WorkspaceLayout>
    );
}
