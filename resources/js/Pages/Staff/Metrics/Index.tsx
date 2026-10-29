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

export type NewsletterBreakdown = {
    newsletter: string;
    campaigns: number;
    sent: number;
    opened: number;
    clicked: number;
    open_rate: number;
    click_rate: number;
};

const SUPPRESSION_LABELS: Record<string, string> = {
    bounced: 'Bounced',
    complained: 'Marked as spam',
    unsubscribed_mailgun: 'Unsubscribed through the email provider',
    repeated_soft_bounce: 'Repeated temporary failures',
};

export const OPEN_RATE_NOTE =
    'Open rates are indicative: they only count readers who allowed open tracking, and Apple Mail Privacy Protection opens some messages automatically.';

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section aria-label={title} className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold text-body">{title}</h2>
            <StatGrid>{children}</StatGrid>
        </section>
    );
}

export default function Index({
    metrics,
    newsletterBreakdown = [],
    deliverySuppressions = {},
}: {
    metrics: Metrics;
    newsletterBreakdown?: NewsletterBreakdown[];
    deliverySuppressions?: Record<string, number>;
}) {
    const subscriptions = metrics.subscriptions;
    const mrr = subscriptions ? new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(subscriptions.mrr_pence / 100) : null;
    const suppressionEntries = Object.entries(deliverySuppressions);

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
                <StatTile label="Opens" value={metrics.newsletters.opened} hint={`${metrics.newsletters.open_rate}% open rate (indicative)`} icon={MailOpen} />
                <StatTile label="Clicks" value={metrics.newsletters.clicked} hint={`${metrics.newsletters.click_rate}% click rate`} icon={MousePointerClick} />
            </Section>
            <p className="text-sm text-muted">{OPEN_RATE_NOTE}</p>
            {newsletterBreakdown.length > 0 && (
                <section aria-labelledby="metrics-by-newsletter-heading" className="sg-card flex flex-col gap-3 p-5 sm:p-6">
                    <h2 id="metrics-by-newsletter-heading" className="text-lg font-semibold text-body">
                        By newsletter
                    </h2>
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <caption className="sr-only">Sends, opens and clicks for each newsletter</caption>
                            <thead>
                                <tr className="text-muted">
                                    <th scope="col" className="py-1 pr-3 font-semibold">
                                        Newsletter
                                    </th>
                                    <th scope="col" className="py-1 pr-3 font-semibold">
                                        Emails
                                    </th>
                                    <th scope="col" className="py-1 pr-3 font-semibold">
                                        Sent
                                    </th>
                                    <th scope="col" className="py-1 pr-3 font-semibold">
                                        Opens (indicative)
                                    </th>
                                    <th scope="col" className="py-1 font-semibold">
                                        Clicks
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {newsletterBreakdown.map((row) => (
                                    <tr key={row.newsletter} className="border-t border-border">
                                        <th scope="row" className="py-2 pr-3 font-semibold text-body">
                                            {row.newsletter}
                                        </th>
                                        <td className="py-2 pr-3">{row.campaigns}</td>
                                        <td className="py-2 pr-3">{row.sent}</td>
                                        <td className="py-2 pr-3">
                                            {row.opened} ({row.open_rate}%)
                                        </td>
                                        <td className="py-2">
                                            {row.clicked} ({row.click_rate}%)
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            {suppressionEntries.length > 0 && (
                <section aria-labelledby="metrics-suppressions-heading" className="sg-card flex flex-col gap-2 p-5 text-sm sm:p-6">
                    <h2 id="metrics-suppressions-heading" className="text-lg font-semibold text-body">
                        Addresses blocked by delivery problems
                    </h2>
                    <ul className="list-disc pl-5 text-body">
                        {suppressionEntries.map(([reason, total]) => (
                            <li key={reason}>
                                {SUPPRESSION_LABELS[reason] ?? reason}: {total}
                            </li>
                        ))}
                    </ul>
                </section>
            )}
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
