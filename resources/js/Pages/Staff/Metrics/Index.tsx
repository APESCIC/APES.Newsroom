import { Head } from '@inertiajs/react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type Metrics = {
    web: { views_7d: number; views_30d: number; signed_in_share_7d: number };
    newsletters: { sent: number; opened: number; clicked: number; open_rate: number; click_rate: number };
    subscriptions: { free: number; active: number; past_due: number; canceled: number; mrr_pence: number };
};

function Stat({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <div className="glass-form-panel">
            <p className="text-sm text-muted">{label}</p>
            <p className="mt-2 text-2xl font-bold text-body">{value}</p>
            {hint && <p className="mt-1 text-xs text-muted">{hint}</p>}
        </div>
    );
}

export default function Index({ metrics }: { metrics: Metrics }) {
    const mrr = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(
        metrics.subscriptions.mrr_pence / 100,
    );

    return (
        <WorkspaceLayout area="Staff" active="metrics" title="Metrics">
            <Head title="Metrics" />
            <div className="mx-auto max-w-4xl flex flex-col gap-8">
                <p className="text-sm text-muted">
                    First-party web views (no analytics cookie), newsletter open/click tokens, and membership counts. Data stays on this app.
                </p>

                <section>
                    <h2 className="text-lg font-bold text-body">Web</h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        <Stat label="Views (7 days)" value={String(metrics.web.views_7d)} />
                        <Stat label="Views (30 days)" value={String(metrics.web.views_30d)} />
                        <Stat label="Signed-in share (7d)" value={`${metrics.web.signed_in_share_7d}%`} />
                    </div>
                </section>

                <section>
                    <h2 className="text-lg font-bold text-body">Newsletters</h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        <Stat label="Accepted sends" value={String(metrics.newsletters.sent)} />
                        <Stat label="Opens" value={String(metrics.newsletters.opened)} hint={`${metrics.newsletters.open_rate}% open rate`} />
                        <Stat label="Clicks" value={String(metrics.newsletters.clicked)} hint={`${metrics.newsletters.click_rate}% click rate`} />
                    </div>
                </section>

                <section>
                    <h2 className="text-lg font-bold text-body">Subscriptions</h2>
                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                        <Stat label="Free members" value={String(metrics.subscriptions.free)} />
                        <Stat label="Active paid" value={String(metrics.subscriptions.active)} />
                        <Stat label="Test-mode MRR" value={mrr} hint="Sum of active plan amounts (yearly ÷ 12)" />
                    </div>
                </section>
            </div>
        </WorkspaceLayout>
    );
}
