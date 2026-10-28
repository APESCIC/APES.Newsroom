import { Eye, Gift, MailOpen, MousePointerClick, PoundSterling, Send, UserCheck, Users, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import AdvancedDetails from '../../../Components/Workspace/ui/AdvancedDetails';
import StatTile, { StatGrid } from '../../../Components/Workspace/ui/StatTile';

type Metrics = {
    web: { views_7d: number; views_30d: number; signed_in_share_7d: number };
    newsletters: { sent: number; opened: number; clicked: number; open_rate: number; click_rate: number };
    /** Only sent to admins (#357). */
    subscriptions: { free: number; active: number; past_due: number; canceled: number; comped: number; mrr_pence: number } | null;
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
    const subscriptions = metrics.subscriptions;
    const mrr = subscriptions ? new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(subscriptions.mrr_pence / 100) : null;

    return (
        <WorkspaceLayout
            area="Staff"
            active="metrics"
            title="Metrics"
            subtitle="Page views and newsletter engagement, measured on this site without analytics cookies."
        >
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
            {subscriptions && (
                <AdvancedDetails summary="Membership revenue">
                    <StatGrid label="Subscriptions">
                        <StatTile label="Free members" value={subscriptions.free} icon={Users} />
                        <StatTile label="Active paid" value={subscriptions.active} icon={Wallet} />
                        <StatTile label="Complimentary" value={subscriptions.comped} hint="Paid access granted by an admin" icon={Gift} />
                        <StatTile label="MRR (test mode)" value={mrr} hint="Sum of active plan amounts (yearly ÷ 12)" icon={PoundSterling} />
                    </StatGrid>
                </AdvancedDetails>
            )}
        </WorkspaceLayout>
    );
}
