import { Head } from '@inertiajs/react';
import { Eye, Gift, MailOpen, MousePointerClick, PoundSterling, Send, UserCheck, Users, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import StatTile, { StatGrid } from '../../../Components/Workspace/ui/StatTile';

type Metrics = {
    web: { views_7d: number; views_30d: number; signed_in_share_7d: number };
    newsletters: { sent: number; opened: number; clicked: number; open_rate: number; click_rate: number };
    subscriptions: { free: number; active: number; past_due: number; canceled: number; comped: number; mrr_pence: number };
};

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section aria-label={title} className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold text-body">{title}</h2>
            <StatGrid>{children}</StatGrid>
        </section>
    );
}

export default function Index({ metrics }: { metrics: Metrics }) {
    const mrr = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(metrics.subscriptions.mrr_pence / 100);

    return (
        <WorkspaceLayout
            area="Staff"
            active="metrics"
            title="Metrics"
            subtitle="First-party web views (no analytics cookie), newsletter open/click tokens, and membership counts. Data stays on this app."
        >
            <Head title="Metrics" />
            <Section title="Web">
                <StatTile label="Views (7 days)" value={metrics.web.views_7d} icon={Eye} />
                <StatTile label="Views (30 days)" value={metrics.web.views_30d} icon={Eye} />
                <StatTile label="Signed-in share (7d)" value={`${metrics.web.signed_in_share_7d}%`} icon={UserCheck} />
            </Section>
            <Section title="Newsletters">
                <StatTile label="Accepted sends" value={metrics.newsletters.sent} icon={Send} />
                <StatTile label="Opens" value={metrics.newsletters.opened} hint={`${metrics.newsletters.open_rate}% open rate`} icon={MailOpen} />
                <StatTile label="Clicks" value={metrics.newsletters.clicked} hint={`${metrics.newsletters.click_rate}% click rate`} icon={MousePointerClick} />
            </Section>
            <Section title="Subscriptions">
                <StatTile label="Free members" value={metrics.subscriptions.free} icon={Users} />
                <StatTile label="Active paid" value={metrics.subscriptions.active} icon={Wallet} />
                <StatTile label="Complimentary" value={metrics.subscriptions.comped} hint="Paid access granted by an admin" icon={Gift} />
                <StatTile label="Test-mode MRR" value={mrr} hint="Sum of active plan amounts (yearly ÷ 12)" icon={PoundSterling} />
            </Section>
        </WorkspaceLayout>
    );
}
