import { Link } from '@inertiajs/react';
import {
    CalendarClock,
    ClipboardCheck,
    Eye,
    FilePen,
    History,
    LayoutTemplate,
    MailOpen,
    MailPlus,
    PoundSterling,
    Rocket,
    Send,
    SquarePen,
    Wallet,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import WorkspaceLayout from '../../Components/Layout/WorkspaceLayout';
import { PostStatusBadge } from '../../Components/Staff/PostBadges';
import AdvancedDetails from '../../Components/Workspace/ui/AdvancedDetails';
import GlassCard from '../../Components/Workspace/ui/GlassCard';
import PillButton from '../../Components/Workspace/ui/PillButton';
import StatTile, { StatGrid } from '../../Components/Workspace/ui/StatTile';

type DashboardPost = {
    id: number;
    title: string;
    status: string;
    channel: string;
    author: string | null;
    updated_at: string | null;
    scheduled_for: string | null;
};

type Props = {
    counts: { draft: number; in_review: number; scheduled: number; published_7d: number };
    attention: { review: DashboardPost[] | null; drafts: DashboardPost[]; scheduled: DashboardPost[] };
    recent: DashboardPost[];
    insights: {
        web: { views_7d: number; views_30d: number; signed_in_share_7d: number };
        newsletters: { sent: number; opened: number; clicked: number; open_rate: number; click_rate: number };
        subscriptions: { free: number; active: number; past_due: number; canceled: number; mrr_pence: number } | null;
    };
    canReview: boolean;
};

function formatDate(value: string | null, withTime = false): string {
    if (!value) return '—';
    return new Intl.DateTimeFormat('en-GB', withTime ? { dateStyle: 'medium', timeStyle: 'short' } : { dateStyle: 'medium' }).format(new Date(value));
}

function PostList({ posts, empty, meta }: { posts: DashboardPost[]; empty: string; meta: (post: DashboardPost) => ReactNode }) {
    if (posts.length === 0) {
        return <p className="text-sm text-muted">{empty}</p>;
    }

    return (
        <ul className="flex flex-col divide-y divide-white/60">
            {posts.map((post) => (
                <li key={post.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 first:pt-0 last:pb-0">
                    <span className="min-w-0">
                        <Link href={`/staff/posts/${post.id}/edit`} className="font-semibold text-body hover:text-teal-deep hover:underline">
                            {post.title}
                        </Link>
                        <span className="block text-xs text-muted">{meta(post)}</span>
                    </span>
                    <PostStatusBadge status={post.status} />
                </li>
            ))}
        </ul>
    );
}

function AttentionCard({ title, icon: Icon, href, linkLabel, children }: { title: string; icon: LucideIcon; href: string; linkLabel: string; children: ReactNode }) {
    return (
        <GlassCard
            as="section"
            aria-label={title}
            title={
                <span className="flex items-center gap-2">
                    <Icon aria-hidden="true" className="h-4 w-4 text-teal-deep" />
                    {title}
                </span>
            }
            actions={
                <Link href={href} className="text-sm font-semibold text-teal-deep hover:underline">
                    {linkLabel}
                </Link>
            }
        >
            {children}
        </GlassCard>
    );
}

export default function StaffDashboard({ counts, attention, recent, insights, canReview }: Props) {
    const gbp = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });

    const actions = (
        <>
            <PillButton href="/staff/newsletters/new" variant="secondary" icon={MailPlus}>
                New newsletter
            </PillButton>
            <PillButton href="/staff/pages/new" variant="secondary" icon={LayoutTemplate}>
                New page
            </PillButton>
            <PillButton href="/staff/posts/new" icon={SquarePen}>
                New post
            </PillButton>
        </>
    );

    return (
        <WorkspaceLayout area="Staff" active="dashboard" title="Dashboard" subtitle="What needs your attention in the newsroom today" actions={actions}>
            <StatGrid label="Post summary">
                <StatTile label="Drafts" value={counts.draft} icon={FilePen} href="/staff/posts?status=draft" />
                <StatTile label="In review" value={counts.in_review} icon={ClipboardCheck} href="/staff/posts?status=in_review" />
                <StatTile label="Scheduled" value={counts.scheduled} icon={CalendarClock} href="/staff/posts?status=scheduled" />
                <StatTile label="Published (7 days)" value={counts.published_7d} icon={Rocket} href="/staff/posts?status=published" />
            </StatGrid>

            <section aria-labelledby="dashboard-attention" className="flex flex-col gap-3">
                <h2 id="dashboard-attention" className="text-lg font-semibold text-body">
                    Needs attention
                </h2>
                <div className={`grid gap-4 lg:grid-cols-2 ${canReview ? 'xl:grid-cols-3' : ''}`}>
                    {canReview && attention.review && (
                        <AttentionCard title="Review queue" icon={ClipboardCheck} href="/staff/posts/review" linkLabel="Open review queue">
                            <PostList
                                posts={attention.review}
                                empty="Nothing is waiting for review."
                                meta={(post) => `By ${post.author ?? 'unknown'} · submitted ${formatDate(post.updated_at)}`}
                            />
                        </AttentionCard>
                    )}
                    <AttentionCard title="Your drafts" icon={FilePen} href="/staff/posts?status=draft" linkLabel="All drafts">
                        <PostList posts={attention.drafts} empty="You have no drafts in progress." meta={(post) => `Updated ${formatDate(post.updated_at)}`} />
                    </AttentionCard>
                    <AttentionCard title="Scheduled" icon={CalendarClock} href="/staff/posts?status=scheduled" linkLabel="All scheduled">
                        <PostList
                            posts={attention.scheduled}
                            empty="No posts are scheduled."
                            meta={(post) => `Goes live ${formatDate(post.scheduled_for, true)}`}
                        />
                    </AttentionCard>
                </div>
            </section>

            <AttentionCard title="Recent activity" icon={History} href="/staff/posts" linkLabel="All posts">
                <PostList
                    posts={recent}
                    empty="No posts yet. Start a new draft to get going."
                    meta={(post) => `By ${post.author ?? 'unknown'} · updated ${formatDate(post.updated_at)}`}
                />
            </AttentionCard>

            <section aria-labelledby="dashboard-insights" className="flex flex-col gap-3">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="dashboard-insights" className="text-lg font-semibold text-body">
                        Insights
                    </h2>
                    <Link href="/staff/metrics" className="text-sm font-semibold text-teal-deep hover:underline">
                        All metrics
                    </Link>
                </div>
                <StatGrid label="Reader insights">
                    <StatTile label="Views (7 days)" value={insights.web.views_7d} icon={Eye} />
                    <StatTile label="Views (30 days)" value={insights.web.views_30d} icon={Eye} />
                    <StatTile label="Newsletter sends" value={insights.newsletters.sent} icon={Send} />
                    <StatTile label="Open rate" value={`${insights.newsletters.open_rate}%`} icon={MailOpen} />
                </StatGrid>
                {insights.subscriptions && (
                    <AdvancedDetails summary="Membership revenue">
                        <StatGrid label="Membership revenue">
                            <StatTile label="Active paid" value={insights.subscriptions.active} icon={Wallet} />
                            <StatTile
                                label="MRR (test mode)"
                                value={gbp.format(insights.subscriptions.mrr_pence / 100)}
                                hint="Sum of active plan amounts (yearly ÷ 12)"
                                icon={PoundSterling}
                            />
                        </StatGrid>
                    </AdvancedDetails>
                )}
            </section>
        </WorkspaceLayout>
    );
}
