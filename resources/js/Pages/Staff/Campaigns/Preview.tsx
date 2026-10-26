import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

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
        <>
            <Head title={`Campaign preview: ${post.title}`} />
            <main className="mx-auto max-w-2xl px-6 py-12">
                <Link href={`/staff/posts/${post.id}/edit`} className="text-sm underline">
                    ← Back to post
                </Link>
                <h1 className="mt-4 text-2xl font-semibold">Campaign preview</h1>
                {status === 'test-send-queued' && (
                    <p className="mt-2 text-sm text-green-700">Test send queued. It cannot trigger a live campaign.</p>
                )}

                <p className={`mt-4 text-sm ${snapshot.sender_verified ? 'text-muted' : 'text-warning'}`}>
                    {snapshot.sender
                        ? `From: ${snapshot.sender.name ? `${snapshot.sender.name} <${snapshot.sender.address}>` : snapshot.sender.address}${snapshot.sender.reply_to ? ` · Reply-to: ${snapshot.sender.reply_to}` : ''}`
                        : 'No newsletter sender is set.'}
                    {snapshot.sender_verified ? '' : ' Sender not verified in Mailgun yet, so sending is blocked.'}
                </p>

                <article className="mt-8 rounded-card border border-border p-6">
                    <p className="text-sm text-muted">
                        {snapshot.channel_label} · {snapshot.author}
                    </p>
                    <h2 className="mt-2 text-xl font-bold text-body">{snapshot.title}</h2>
                    {snapshot.excerpt && <p className="mt-3 text-body">{snapshot.excerpt}</p>}
                    <a href={snapshot.read_more_url} className="mt-4 inline-block text-sm underline">
                        Read the full story
                    </a>
                </article>

                <form onSubmit={sendTest} className="mt-8 flex flex-col gap-3 border-t pt-6">
                    <h2 className="font-medium">Test send</h2>
                    <p className="text-sm text-muted">Enter an explicit recipient. Never pre-filled from live lists.</p>
                    <input
                        type="email"
                        required
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="you@example.com"
                        className="w-full max-w-md rounded border px-3 py-2"
                    />
                    {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
                    <button type="submit" disabled={processing} className="w-fit rounded bg-apes-primary px-4 py-2 text-white">
                        Queue test send
                    </button>
                </form>
            </main>
        </>
    );
}
