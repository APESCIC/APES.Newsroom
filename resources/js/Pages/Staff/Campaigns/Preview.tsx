import { useForm } from '@inertiajs/react';
import { ArrowLeft, Send } from 'lucide-react';
import { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge, { type BadgeTone } from '../../../Components/Workspace/ui/Badge';
import { OPEN_RATE_NOTE } from '../Metrics/Index';
import { FormSection } from '../../../Components/Workspace/ui/FormField';
import PillButton from '../../../Components/Workspace/ui/PillButton';

type Snapshot = {
    title: string;
    excerpt: string | null;
    author: string;
    channel_label: string;
    published_at: string | null;
    read_more_url: string;
    sender: { address: string; name: string | null; reply_to: string | null } | null;
    sender_verified: boolean;
};

export type CampaignStatus = 'draft' | 'queued' | 'sending' | 'paused' | 'completed' | 'failed' | 'cancelled';

export type LiveCampaign = {
    id: number;
    status: CampaignStatus;
    pause_reason: string | null;
    paused_at: string | null;
    queued_at: string | null;
    completed_at: string | null;
    stats: {
        recipients: number;
        sent: number;
        failed: number;
        skipped: number;
        queued: number;
        opened: number;
        clicked: number;
        open_rate: number;
        click_rate: number;
        bounced: number;
        complained: number;
        top_links: { url: string; clicks: number }[];
    };
};

export function campaignStatusLabel(status: CampaignStatus): { label: string; tone: BadgeTone } {
    switch (status) {
        case 'draft':
            return { label: 'Draft', tone: 'neutral' };
        case 'queued':
            return { label: 'Queued', tone: 'info' };
        case 'sending':
            return { label: 'Sending', tone: 'info' };
        case 'paused':
            return { label: 'Paused', tone: 'warning' };
        case 'completed':
            return { label: 'Sent', tone: 'ok' };
        case 'failed':
            return { label: 'Not sent', tone: 'error' };
        case 'cancelled':
            return { label: 'Cancelled', tone: 'neutral' };
        default: {
            const exhaustive: never = status;
            return exhaustive;
        }
    }
}

function LiveCampaignCard({ postId, campaign, canResume, transport }: { postId: number; campaign: LiveCampaign; canResume: boolean; transport: string | null }) {
    const resume = useForm({});
    const { label, tone } = campaignStatusLabel(campaign.status);
    const stats = campaign.stats;

    return (
        <section aria-labelledby="live-campaign-heading" className="sg-card flex flex-col gap-4 p-5 sm:p-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="live-campaign-heading" className="text-base font-semibold text-body">
                    Live send
                </h2>
                <Badge tone={tone}>{label}</Badge>
            </div>
            {campaign.status === 'paused' && (
                <div role="alert" className="flex flex-col gap-2 rounded-xl border border-border bg-white/50 p-3 text-sm">
                    <p className="font-semibold text-body">
                        Sending paused because the newsletter mail transport{transport ? ` (${transport})` : ''} failed. {stats.queued} recipients are
                        still waiting.
                    </p>
                    {campaign.pause_reason && <p className="text-body">{campaign.pause_reason}</p>}
                    {canResume ? (
                        <button
                            type="button"
                            className="sg-btn sg-btn-primary sg-btn-sm self-start"
                            disabled={resume.processing}
                            onClick={() => resume.post(`/staff/posts/${postId}/campaign/resume`, { preserveScroll: true })}
                        >
                            Resume sending
                        </button>
                    ) : (
                        <p className="text-muted">An admin can resume it once the transport is fixed.</p>
                    )}
                </div>
            )}
            <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt className="text-muted">Sent</dt>
                    <dd className="text-lg font-semibold text-body">{stats.sent}</dd>
                </div>
                <div>
                    <dt className="text-muted">Opens (indicative)</dt>
                    <dd className="text-lg font-semibold text-body">
                        {stats.opened} <span className="text-sm font-normal text-muted">({stats.open_rate}%)</span>
                    </dd>
                </div>
                <div>
                    <dt className="text-muted">Clicks</dt>
                    <dd className="text-lg font-semibold text-body">
                        {stats.clicked} <span className="text-sm font-normal text-muted">({stats.click_rate}%)</span>
                    </dd>
                </div>
                <div>
                    <dt className="text-muted">Bounced or spam</dt>
                    <dd className="text-lg font-semibold text-body">{stats.bounced + stats.complained}</dd>
                </div>
            </dl>
            <p className="text-xs text-muted">
                {stats.failed} failed, {stats.skipped} skipped (unsubscribed, suppressed or no consent), {stats.queued} waiting. {OPEN_RATE_NOTE}
            </p>
            {stats.top_links.length > 0 && (
                <div className="text-sm">
                    <h3 className="font-semibold text-body">Top links</h3>
                    <ol className="mt-1 list-decimal pl-5 text-body">
                        {stats.top_links.map((link) => (
                            <li key={link.url} className="break-all">
                                {link.url} <span className="text-muted">({link.clicks} readers)</span>
                            </li>
                        ))}
                    </ol>
                </div>
            )}
        </section>
    );
}

export default function CampaignPreview({
    post,
    snapshot,
    status,
    liveCampaign = null,
    newsletterTransport = null,
    canResume = false,
}: {
    post: { id: number; title: string; status: string; email_on_publish: boolean; mailing_lists: string[] };
    snapshot: Snapshot;
    status?: string;
    liveCampaign?: LiveCampaign | null;
    newsletterTransport?: string | null;
    canResume?: boolean;
}) {
    const { data, setData, post: submit, processing, errors } = useForm({ email: '' });

    const sendTest: FormEventHandler = (e) => {
        e.preventDefault();
        submit(`/staff/posts/${post.id}/campaign/test-send`);
    };

    return (
        <WorkspaceLayout
            area="Staff"
            active="posts"
            title="Campaign preview"
            documentTitle={`Campaign preview: ${post.title}`}
            subtitle={post.title}
            actions={
                <PillButton href={`/staff/posts/${post.id}/edit`} variant="secondary" icon={ArrowLeft}>
                    Back to post
                </PillButton>
            }
        >
            {status === 'test-send-queued' && (
                <p role="status" className="sg-card px-4 py-3 text-sm font-medium text-success">
                    Test send queued. It cannot trigger a live campaign.
                </p>
            )}

            <div className="sg-card flex flex-wrap items-center gap-2 px-4 py-3 text-sm">
                {snapshot.sender_verified ? <Badge tone="ok">Sender verified</Badge> : <Badge tone="warning">Sender not verified</Badge>}
                <span className="text-body">
                    {snapshot.sender
                        ? `From: ${snapshot.sender.name ? `${snapshot.sender.name} <${snapshot.sender.address}>` : snapshot.sender.address}${snapshot.sender.reply_to ? ` · Reply-to: ${snapshot.sender.reply_to}` : ''}`
                        : 'No newsletter sender is set.'}
                    {snapshot.sender_verified ? '' : ' Sender not verified yet, so sending is blocked.'}
                </span>
            </div>

            {liveCampaign && <LiveCampaignCard postId={post.id} campaign={liveCampaign} canResume={canResume} transport={newsletterTransport} />}
            {newsletterTransport && <p className="text-xs text-muted">Newsletters currently send through {newsletterTransport}.</p>}

            <article aria-label="Email preview" className="sg-card mx-auto w-full max-w-2xl p-6 sm:p-8">
                <p className="text-sm text-muted">
                    {snapshot.channel_label} · {snapshot.author}
                </p>
                <h2 className="mt-2 text-xl font-bold text-body">{snapshot.title}</h2>
                {snapshot.excerpt && <p className="mt-3 text-body">{snapshot.excerpt}</p>}
                <a href={snapshot.read_more_url} className="mt-4 inline-block text-sm font-semibold text-teal-deep underline">
                    Read the full story
                </a>
            </article>

            <FormSection title="Test send" description="Enter an explicit recipient. Never pre-filled from live lists.">
                <form onSubmit={sendTest} className="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <label htmlFor="test-recipient" className="sr-only">
                        Test recipient email
                    </label>
                    <div className="flex-1">
                        <input
                            id="test-recipient"
                            type="email"
                            required
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="you@example.com"
                            className="form-input"
                        />
                        {errors.email && <p className="mt-1 text-sm text-danger">{errors.email}</p>}
                    </div>
                    <button type="submit" disabled={processing} className="sg-btn sg-btn-primary">
                        <Send aria-hidden="true" className="h-4 w-4" />
                        Queue test send
                    </button>
                </form>
            </FormSection>
        </WorkspaceLayout>
    );
}
