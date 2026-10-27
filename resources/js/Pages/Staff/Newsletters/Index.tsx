import { Head, Link } from '@inertiajs/react';
import LineIcon from '../../../Components/Icons/LineIcon';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type NewsletterStatus = 'active' | 'archived';
type StatusFilter = 'all' | NewsletterStatus;

type NewsletterRow = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    legacy_list: string | null;
    status: NewsletterStatus;
    sender_name: string | null;
    sender_email: string | null;
    sender_verified: boolean;
    channel: string | null;
    show_on_home: boolean;
    show_in_nav: boolean;
    display_order: number;
    confirmed_count: number;
    pending_count: number;
    unsubscribed_count: number;
    account_confirmed_count: number;
    mailing_only_confirmed_count: number;
};

const FILTERS: Array<{ value: StatusFilter; label: string }> = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active' },
    { value: 'archived', label: 'Archived' },
];

function StatusBadge({ status }: { status: NewsletterStatus }) {
    switch (status) {
        case 'active':
            return <span className="inline-flex rounded-full bg-success-mist px-2 py-0.5 text-xs font-bold text-success">Active</span>;
        case 'archived':
            return <span className="inline-flex rounded-full bg-page-tint px-2 py-0.5 text-xs font-bold text-muted">Archived</span>;
        default: {
            const unreachable: never = status;
            return unreachable;
        }
    }
}

export default function NewslettersIndex({ newsletters, status }: { newsletters: NewsletterRow[]; status: StatusFilter }) {
    return (
        <WorkspaceLayout
            area="Staff"
            active="newsletters"
            title="Newsletters"
            subtitle="Every newsletter members can subscribe to, including the three channel lists"
            actions={
                <Link href="/staff/newsletters/new" className="button-primary">
                    <LineIcon name="plus" className="h-4 w-4" />
                    New newsletter
                </Link>
            }
        >
            <Head title="Newsletters" />
            <nav aria-label="Filter newsletters by status" className="mb-4 flex gap-2 text-sm">
                {FILTERS.map((filter) => (
                    <Link
                        key={filter.value}
                        href={filter.value === 'all' ? '/staff/newsletters' : `/staff/newsletters?status=${filter.value}`}
                        aria-current={status === filter.value ? 'page' : undefined}
                        className={`rounded border px-3 py-1 ${status === filter.value ? 'border-teal-deep font-semibold text-teal-deep' : 'border-neutral-200'}`}
                    >
                        {filter.label}
                    </Link>
                ))}
            </nav>
            <ul className="divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white">
                {newsletters.map((newsletter) => (
                    <li key={newsletter.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div>
                            <div className="flex items-center gap-2">
                                <Link href={`/staff/newsletters/${newsletter.id}/edit`} className="font-semibold text-teal-deep hover:underline">
                                    {newsletter.name}
                                </Link>
                                <StatusBadge status={newsletter.status} />
                                {newsletter.channel && newsletter.status === 'active' && (
                                    <span className="inline-flex rounded-full bg-page-tint px-2 py-0.5 text-xs font-bold text-teal-deep">
                                        Public channel #{newsletter.display_order}
                                        {newsletter.show_on_home ? ' · home' : ''}
                                        {newsletter.show_in_nav ? ' · menu' : ''}
                                    </span>
                                )}
                            </div>
                            <p className="text-sm text-neutral-600">
                                /{newsletter.slug}
                                {newsletter.legacy_list ? ` · channel list ${newsletter.legacy_list}` : ''}
                                {newsletter.sender_email ? ` · from ${newsletter.sender_name ?? newsletter.sender_email} <${newsletter.sender_email}>` : ' · no sender set'}
                                {newsletter.sender_email ? (newsletter.sender_verified ? ' · sender verified' : ' · sender not verified') : ''}
                            </p>
                        </div>
                        <p className="text-sm text-neutral-700">
                            {newsletter.confirmed_count} confirmed
                            {' '}({newsletter.account_confirmed_count} account · {newsletter.mailing_only_confirmed_count} mailing-only)
                            {' '}· {newsletter.pending_count} pending · {newsletter.unsubscribed_count} unsubscribed
                        </p>
                    </li>
                ))}
            </ul>
        </WorkspaceLayout>
    );
}
