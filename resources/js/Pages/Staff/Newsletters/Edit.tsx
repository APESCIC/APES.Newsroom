import { Head, Link, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type NewsletterData = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    legacy_list: string | null;
    archived_at: string | null;
};

export default function NewsletterEdit({
    newsletter,
    segments = [],
    otherNewsletters = [],
}: {
    newsletter: NewsletterData | null;
    segments?: Array<{ id: number; name: string; also: string }>;
    otherNewsletters?: Array<{ id: number; name: string }>;
}) {
    const form = useForm({
        name: newsletter?.name ?? '',
        slug: newsletter?.slug ?? '',
        description: newsletter?.description ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (newsletter) {
            form.patch(`/staff/newsletters/${newsletter.id}`);
        } else {
            form.post('/staff/newsletters');
        }
    };

    return (
        <WorkspaceLayout area="Staff" active="newsletters" title={newsletter ? 'Edit newsletter' : 'New newsletter'}>
            <Head title={newsletter ? newsletter.name : 'New newsletter'} />
            <p className="mb-4 text-sm">
                <Link href="/staff/newsletters" className="text-teal-deep hover:underline">
                    All newsletters
                </Link>
            </p>
            <form onSubmit={submit} className="max-w-xl space-y-4 rounded-lg border border-neutral-200 bg-white p-4">
                <label className="block text-sm font-semibold" htmlFor="name">
                    Name
                    <input id="name" className="mt-1 w-full rounded border px-3 py-2" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                </label>
                {form.errors.name && <p className="text-sm text-red-700">{form.errors.name}</p>}
                <label className="block text-sm font-semibold" htmlFor="slug">
                    Slug
                    <input id="slug" className="mt-1 w-full rounded border px-3 py-2" value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} />
                </label>
                {form.errors.slug && <p className="text-sm text-red-700">{form.errors.slug}</p>}
                <label className="block text-sm font-semibold" htmlFor="description">
                    Description
                    <textarea id="description" className="mt-1 w-full rounded border px-3 py-2" rows={4} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                </label>
                {newsletter?.legacy_list && (
                    <p className="text-sm text-neutral-600">Linked channel list: {newsletter.legacy_list}. Channel signups keep using this list.</p>
                )}
                <div className="flex flex-wrap gap-2">
                    <button type="submit" className="button-primary" disabled={form.processing}>
                        Save
                    </button>
                    {newsletter && !newsletter.archived_at && (
                        <button type="button" className="rounded border px-3 py-2 text-sm" onClick={() => router.post(`/staff/newsletters/${newsletter.id}/archive`)}>
                            Archive
                        </button>
                    )}
                    {newsletter?.archived_at && (
                        <button type="button" className="rounded border px-3 py-2 text-sm" onClick={() => router.post(`/staff/newsletters/${newsletter.id}/restore`)}>
                            Restore
                        </button>
                    )}
                </div>
            </form>
            {newsletter && (
                <section className="mt-6 max-w-xl space-y-3">
                    <h2 className="font-semibold">Segments</h2>
                    <ul className="text-sm">
                        {segments.map((segment) => (
                            <li key={segment.id}>
                                {segment.name} — also confirmed on {segment.also}
                            </li>
                        ))}
                    </ul>
                    {otherNewsletters.length > 0 && (
                        <form
                            className="space-y-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const data = new FormData(event.currentTarget);
                                router.post(`/staff/newsletters/${newsletter.id}/segments`, {
                                    name: String(data.get('name') ?? ''),
                                    also_newsletter_id: String(data.get('also_newsletter_id') ?? ''),
                                });
                            }}
                        >
                            <input name="name" placeholder="Segment name" className="w-full rounded border px-3 py-2" required />
                            <select name="also_newsletter_id" className="w-full rounded border px-3 py-2" required defaultValue="">
                                <option value="" disabled>
                                    Also confirmed on
                                </option>
                                {otherNewsletters.map((other) => (
                                    <option key={other.id} value={other.id}>
                                        {other.name}
                                    </option>
                                ))}
                            </select>
                            <button type="submit" className="rounded border px-3 py-2 text-sm">
                                Add segment
                            </button>
                        </form>
                    )}
                </section>
            )}
        </WorkspaceLayout>
    );
}
