import { useForm } from '@inertiajs/react';
import { ArrowLeft, Send } from 'lucide-react';
import { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
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

export default function CampaignPreview({
    post,
    snapshot,
    status,
}: {
    post: { id: number; title: string; status: string; email_on_publish: boolean; mailing_lists: string[] };
    snapshot: Snapshot;
    status?: string;
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
                    {snapshot.sender_verified ? '' : ' Sender not verified in Mailgun yet, so sending is blocked.'}
                </span>
            </div>

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
