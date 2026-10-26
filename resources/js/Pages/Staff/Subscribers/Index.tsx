import { Head, Link, router } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import LineIcon from '../../../Components/Icons/LineIcon';

type SubscriptionRow = {
    newsletter: string;
    status: string;
    opted_out: boolean;
};

type SubscriberRow = {
    id: number;
    email: string;
    name: string | null;
    signed_up_at: string;
    suppressed: boolean;
    source: string | null;
    consent_action: string | null;
    subscriptions: SubscriptionRow[];
};

type Paginated<T> = {
    data: T[];
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
    from: number | null;
    to: number | null;
};

type NewsletterOption = { id: number; name: string };

type Filters = {
    q: string;
    newsletter_id: number | null;
    source: string;
};

function formatWhen(iso: string): string {
    if (!iso) {
        return '—';
    }
    try {
        return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

export default function SubscribersIndex({
    filters,
    newsletters,
    sources,
    subscribers,
}: {
    filters: Filters;
    newsletters: NewsletterOption[];
    sources: string[];
    subscribers: Paginated<SubscriberRow>;
}) {
    const [q, setQ] = useState(filters.q);
    const [newsletterId, setNewsletterId] = useState(filters.newsletter_id?.toString() ?? '');
    const [source, setSource] = useState(filters.source);

    const applyFilters: FormEventHandler = (e) => {
        e.preventDefault();
        router.get(
            '/staff/subscribers',
            {
                q: q || undefined,
                newsletter_id: newsletterId || undefined,
                source: source || undefined,
            },
            { preserveState: true },
        );
    };

    const exportHref = (() => {
        const params = new URLSearchParams();
        if (filters.q) params.set('q', filters.q);
        if (filters.newsletter_id) params.set('newsletter_id', String(filters.newsletter_id));
        if (filters.source) params.set('source', filters.source);
        const qs = params.toString();
        return `/staff/subscribers/export${qs ? `?${qs}` : ''}`;
    })();

    return (
        <WorkspaceLayout
            area="Staff"
            active="subscribers"
            title="Mailing-list subscribers"
            subtitle="Contacts on newsletters who do not have a Newsroom account. Read-only — manage opt-outs via mailing tools, not here."
            actions={
                <a href={exportHref} className="button-secondary inline-flex items-center gap-2">
                    <LineIcon name="document" className="h-4 w-4" />
                    Export CSV
                </a>
            }
        >
            <Head title="Mailing-list subscribers" />

            <form onSubmit={applyFilters} className="mb-4 flex flex-wrap items-end gap-3">
                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-semibold text-body">Search</span>
                    <input
                        type="search"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        className="rounded border border-neutral-200 px-3 py-2"
                        placeholder="Email or name"
                    />
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-semibold text-body">Newsletter</span>
                    <select
                        value={newsletterId}
                        onChange={(e) => setNewsletterId(e.target.value)}
                        className="rounded border border-neutral-200 px-3 py-2"
                    >
                        <option value="">All</option>
                        {newsletters.map((n) => (
                            <option key={n.id} value={n.id}>
                                {n.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-semibold text-body">Source</span>
                    <select
                        value={source}
                        onChange={(e) => setSource(e.target.value)}
                        className="rounded border border-neutral-200 px-3 py-2"
                    >
                        <option value="">All</option>
                        {sources.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>
                </label>
                <button type="submit" className="button-primary">
                    Filter
                </button>
            </form>

            <p className="mb-3 text-sm text-muted">
                {subscribers.total === 0
                    ? 'No mailing-only subscribers match these filters.'
                    : `Showing ${subscribers.from}–${subscribers.to} of ${subscribers.total}`}
            </p>

            <ul className="divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white">
                {subscribers.data.map((row) => (
                    <li key={row.id} className="px-4 py-3">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <div>
                                <p className="font-semibold text-body">{row.email}</p>
                                {row.name && <p className="text-sm text-muted">{row.name}</p>}
                            </div>
                            <p className="text-sm text-muted">Signed up {formatWhen(row.signed_up_at)}</p>
                        </div>
                        <dl className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-muted">Source / consent</dt>
                                <dd className="text-body">
                                    {row.source ?? '—'}
                                    {row.consent_action ? ` · ${row.consent_action}` : ''}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted">Suppression</dt>
                                <dd className={row.suppressed ? 'font-semibold text-danger' : 'text-body'}>
                                    {row.suppressed ? 'Suppressed / opted out globally' : 'Not suppressed'}
                                </dd>
                            </div>
                        </dl>
                        <div className="mt-2">
                            <h3 className="text-sm font-bold text-body">Newsletters</h3>
                            {row.subscriptions.length === 0 ? (
                                <p className="text-sm text-muted">No list subscriptions recorded.</p>
                            ) : (
                                <ul className="mt-1 flex flex-col gap-1 text-sm">
                                    {row.subscriptions.map((sub) => (
                                        <li key={`${row.id}-${sub.newsletter}-${sub.status}`}>
                                            <span className="text-body">{sub.newsletter}</span>
                                            <span className="ml-2 text-muted">({sub.status})</span>
                                            {sub.opted_out && (
                                                <span className="ml-2 font-semibold text-danger">opted out</span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </li>
                ))}
            </ul>

            {(subscribers.prev_page_url || subscribers.next_page_url) && (
                <div className="mt-4 flex gap-3">
                    {subscribers.prev_page_url && (
                        <button type="button" className="button-secondary" onClick={() => router.get(subscribers.prev_page_url!)}>
                            Previous
                        </button>
                    )}
                    {subscribers.next_page_url && (
                        <button type="button" className="button-secondary" onClick={() => router.get(subscribers.next_page_url!)}>
                            Next
                        </button>
                    )}
                    <Link href="/staff/newsletters" className="button-secondary">
                        Newsletters
                    </Link>
                </div>
            )}
        </WorkspaceLayout>
    );
}
