import { Head, Link, router } from '@inertiajs/react';
import { Download, Users } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import Pagination from '../../../Components/Workspace/ui/Pagination';

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
    current_page: number;
    last_page: number;
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
            subtitle="Contacts on newsletters who do not have a Newsroom account. Open a subscriber to edit them or change their subscriptions."
            actions={
                <a href={exportHref} className="sg-btn sg-btn-secondary">
                    <Download aria-hidden="true" className="h-4 w-4" />
                    Export CSV
                </a>
            }
        >
            <Head title="Mailing-list subscribers" />

            <form onSubmit={applyFilters} role="search" aria-label="Filter subscribers" className="sg-card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_14rem_12rem_auto] lg:items-end">
                <label className="flex flex-col gap-1.5 text-sm font-semibold text-body">
                    Search
                    <input type="search" value={q} onChange={(e) => setQ(e.target.value)} className="form-input font-normal" placeholder="Email or name" />
                </label>
                <label className="flex flex-col gap-1.5 text-sm font-semibold text-body">
                    Newsletter
                    <select value={newsletterId} onChange={(e) => setNewsletterId(e.target.value)} className="form-input font-normal">
                        <option value="">All</option>
                        {newsletters.map((n) => (
                            <option key={n.id} value={n.id}>
                                {n.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1.5 text-sm font-semibold text-body">
                    Source
                    <select value={source} onChange={(e) => setSource(e.target.value)} className="form-input font-normal">
                        <option value="">All</option>
                        {sources.map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>
                </label>
                <button type="submit" className="sg-btn sg-btn-primary">
                    Filter
                </button>
            </form>

            {subscribers.total === 0 ? (
                <EmptyState icon={Users} title="No subscribers found" description="No mailing-only subscribers match these filters." />
            ) : (
                <>
                    <p className="text-sm text-muted">
                        Showing {subscribers.from}–{subscribers.to} of {subscribers.total}
                    </p>
                    <ul className="sg-card divide-y divide-border">
                        {subscribers.data.map((row) => (
                            <li key={row.id} className="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.2fr)]">
                                <div className="min-w-0">
                                    <Link href={`/staff/people/contacts/${row.id}`} className="font-semibold break-words text-body underline-offset-2 hover:underline">
                                        {row.email}
                                    </Link>
                                    {row.name && <p className="text-sm text-muted">{row.name}</p>}
                                    <p className="mt-1 text-xs text-muted">Signed up {formatWhen(row.signed_up_at)}</p>
                                </div>
                                <div className="text-sm">
                                    <p className="text-muted">
                                        {row.source ?? '—'}
                                        {row.consent_action ? ` · ${row.consent_action}` : ''}
                                    </p>
                                    <p className="mt-1">{row.suppressed ? <Badge tone="error">Suppressed globally</Badge> : <Badge tone="ok">Not suppressed</Badge>}</p>
                                </div>
                                <div className="text-sm">
                                    {row.subscriptions.length === 0 ? (
                                        <p className="text-muted">No list subscriptions recorded.</p>
                                    ) : (
                                        <ul aria-label={`Newsletters for ${row.email}`} className="flex flex-col gap-1">
                                            {row.subscriptions.map((sub) => (
                                                <li key={`${row.id}-${sub.newsletter}-${sub.status}`}>
                                                    <span className="text-body">{sub.newsletter}</span>
                                                    <span className="ml-2 text-muted">({sub.status})</span>
                                                    {sub.opted_out && <span className="ml-2 font-semibold text-danger">opted out</span>}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                    <Pagination page={subscribers} label="Subscribers" />
                </>
            )}
        </WorkspaceLayout>
    );
}
