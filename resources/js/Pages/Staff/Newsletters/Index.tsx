import { Head, Link } from '@inertiajs/react';
import LineIcon from '../../../Components/Icons/LineIcon';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type NewsletterRow = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    legacy_list: string | null;
    archived_at: string | null;
    subscriptions_count: number;
};

export default function NewslettersIndex({ newsletters }: { newsletters: NewsletterRow[] }) {
    return (
        <WorkspaceLayout
            area="Staff"
            active="newsletters"
            title="Newsletters"
            subtitle="Audiences distinct from the three channel mailing lists"
            actions={
                <Link href="/staff/newsletters/new" className="button-primary">
                    <LineIcon name="plus" className="h-4 w-4" />
                    New newsletter
                </Link>
            }
        >
            <Head title="Newsletters" />
            <ul className="divide-y divide-neutral-200 rounded-lg border border-neutral-200 bg-white">
                {newsletters.map((newsletter) => (
                    <li key={newsletter.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div>
                            <Link href={`/staff/newsletters/${newsletter.id}/edit`} className="font-semibold text-teal-deep hover:underline">
                                {newsletter.name}
                            </Link>
                            <p className="text-sm text-neutral-600">
                                /{newsletter.slug}
                                {newsletter.legacy_list ? ` · channel list ${newsletter.legacy_list}` : ''}
                                {newsletter.archived_at ? ' · archived' : ''}
                                {` · ${newsletter.subscriptions_count} subscriptions`}
                            </p>
                        </div>
                    </li>
                ))}
            </ul>
        </WorkspaceLayout>
    );
}
