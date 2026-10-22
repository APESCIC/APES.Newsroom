import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type Endpoint = {
    id: number;
    name: string;
    url: string;
    events: string[];
    is_active: boolean;
    secret_hint: string;
};

function CreateForm({ availableEvents }: { availableEvents: string[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        url: '',
        events: availableEvents,
        is_active: true,
    });

    function toggleEvent(event: string) {
        setData(
            'events',
            data.events.includes(event) ? data.events.filter((e) => e !== event) : [...data.events, event],
        );
    }

    return (
        <form
            className="glass-form-panel flex flex-col gap-3"
            onSubmit={(e: FormEvent) => {
                e.preventDefault();
                post('/admin/webhooks', { onSuccess: () => reset() });
            }}
        >
            <h2 className="text-lg font-bold text-body">Add endpoint</h2>
            <label className="text-sm font-bold text-body">
                Name
                <input className="form-input mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
            </label>
            {errors.name && <p className="text-sm text-danger">{errors.name}</p>}
            <label className="text-sm font-bold text-body">
                URL
                <input className="form-input mt-1" value={data.url} onChange={(e) => setData('url', e.target.value)} required />
            </label>
            {errors.url && <p className="text-sm text-danger">{errors.url}</p>}
            <fieldset>
                <legend className="text-sm font-bold text-body">Events</legend>
                <div className="mt-2 flex flex-col gap-1">
                    {availableEvents.map((event) => (
                        <label key={event} className="flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" checked={data.events.includes(event)} onChange={() => toggleEvent(event)} />
                            {event}
                        </label>
                    ))}
                </div>
            </fieldset>
            <button type="submit" className="button-primary w-fit" disabled={processing}>
                Create endpoint
            </button>
        </form>
    );
}

function EndpointCard({ endpoint, availableEvents }: { endpoint: Endpoint; availableEvents: string[] }) {
    const { data, setData, patch, processing, delete: destroy } = useForm({
        name: endpoint.name,
        url: endpoint.url,
        events: endpoint.events,
        is_active: endpoint.is_active,
        rotate_secret: false,
    });

    function toggleEvent(event: string) {
        setData(
            'events',
            data.events.includes(event) ? data.events.filter((e) => e !== event) : [...data.events, event],
        );
    }

    return (
        <form
            className="glass-form-panel flex flex-col gap-3"
            onSubmit={(e: FormEvent) => {
                e.preventDefault();
                patch(`/admin/webhooks/${endpoint.id}`);
            }}
        >
            <div className="flex items-baseline justify-between gap-3">
                <h2 className="text-lg font-bold text-body">{endpoint.name}</h2>
                <p className="text-xs text-muted">Secret {endpoint.secret_hint}</p>
            </div>
            <label className="text-sm font-bold text-body">
                Name
                <input className="form-input mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} />
            </label>
            <label className="text-sm font-bold text-body">
                URL
                <input className="form-input mt-1" value={data.url} onChange={(e) => setData('url', e.target.value)} />
            </label>
            <fieldset>
                <legend className="text-sm font-bold text-body">Events</legend>
                <div className="mt-2 flex flex-col gap-1">
                    {availableEvents.map((event) => (
                        <label key={event} className="flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" checked={data.events.includes(event)} onChange={() => toggleEvent(event)} />
                            {event}
                        </label>
                    ))}
                </div>
            </fieldset>
            <label className="flex items-center gap-2 text-sm text-body">
                <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                Active
            </label>
            <label className="flex items-center gap-2 text-sm text-body">
                <input type="checkbox" checked={data.rotate_secret} onChange={(e) => setData('rotate_secret', e.target.checked)} />
                Rotate signing secret on save
            </label>
            <div className="flex gap-3">
                <button type="submit" className="button-primary w-fit" disabled={processing}>
                    Save
                </button>
                <button
                    type="button"
                    className="button-secondary w-fit"
                    onClick={() => {
                        if (confirm('Delete this webhook endpoint?')) {
                            destroy(`/admin/webhooks/${endpoint.id}`);
                        }
                    }}
                >
                    Delete
                </button>
            </div>
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
        <WorkspaceLayout area="Admin" active="webhooks" title="Webhooks">
            <Head title="Webhooks" />
            <div className="mx-auto max-w-3xl flex flex-col gap-6">
                <p className="text-sm text-muted">
                    Outbound signed webhooks (HMAC-SHA256). Delivery is queued with retries and never blocks publish or
                    membership flows.
                </p>
                {revealedSecret && (
                    <section className="workspace-glass-card flex flex-col gap-2 p-4" aria-label="Signing secret">
                        <p className="font-semibold text-body">
                            Signing secret for "{revealedSecret.name}". Copy it into the receiving service now: it will not be shown again.
                        </p>
                        <code className="break-all rounded-control bg-white px-3 py-2 font-mono text-sm text-body">{revealedSecret.secret}</code>
                    </section>
                )}
                <CreateForm availableEvents={availableEvents} />
                {endpoints.map((endpoint) => (
                    <EndpointCard key={endpoint.id} endpoint={endpoint} availableEvents={availableEvents} />
                ))}
            </div>
        </WorkspaceLayout>
    );
}
