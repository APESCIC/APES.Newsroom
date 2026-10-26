import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Pagination from '../../../Components/Admin/Pagination';
import type { Paginator } from '../../../Components/Admin/types';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import type { HeroMediaAsset } from '../../../Components/Staff/heroImageUpload';

type LibraryAsset = HeroMediaAsset & {
    uploaded_by: string | null;
    created_at: string | null;
    usage_count: number | null;
};

type Usage = {
    type: 'post' | 'page';
    id: number;
    title: string;
    status: string;
    as_hero: boolean;
    in_body: boolean;
    edit_url: string;
};

type PendingDelete = { asset: LibraryAsset; usage: Usage[] };

function formatDate(value: string | null) {
    return value ? new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value)) : '';
}

function usageLabel(item: Usage) {
    const where = [item.as_hero ? 'hero image' : null, item.in_body ? 'in the body' : null].filter(Boolean).join(' and ');

    return `${item.type === 'post' ? 'Post' : 'Page'}: ${item.title} (${item.status.replaceAll('_', ' ')}, ${where})`;
}

export default function MediaIndex({ assets, search }: { assets: Paginator<LibraryAsset>; search: string }) {
    const { errors, flash } = usePage<{ errors: Record<string, string>; flash?: { status?: string } }>().props;
    const form = useForm({ q: search });
    const [pending, setPending] = useState<PendingDelete | null>(null);
    const [loadError, setLoadError] = useState('');

    const askToDelete = async (asset: LibraryAsset) => {
        setLoadError('');
        try {
            const response = await fetch(`/staff/media/${asset.id}/usage`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const payload = (await response.json()) as { usage: Usage[] };
            setPending({ asset, usage: payload.usage ?? [] });
        } catch {
            setLoadError('Could not check where this image is used. Try again.');
        }
    };

    const confirmDelete = () => {
        if (!pending) {
            return;
        }

        router.delete(`/staff/media/${pending.asset.id}`, {
            data: { confirm: pending.usage.length > 0 },
            preserveScroll: true,
            onFinish: () => setPending(null),
        });
    };

    return (
        <WorkspaceLayout area="Staff" active="media" title="Media library" subtitle="Uploaded hero images you can reuse">
            <Head title="Media library" />
            <form
                role="search"
                className="mb-4 flex max-w-xl gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.get('/staff/media', { preserveState: true });
                }}
            >
                <label htmlFor="media-search" className="sr-only">
                    Search uploaded images
                </label>
                <input
                    id="media-search"
                    className="form-input flex-1"
                    placeholder="Search by file name or alt text"
                    value={form.data.q}
                    onChange={(event) => form.setData('q', event.target.value)}
                />
                <button type="submit" className="button-secondary">
                    Search
                </button>
            </form>

            {flash?.status && (
                <p className="mb-4 rounded-control border border-success bg-success-mist px-3 py-2 text-sm text-success" role="status">
                    {flash.status}
                </p>
            )}
            {(errors?.confirm || loadError) && (
                <p className="mb-4 rounded-control border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                    {errors?.confirm ?? loadError}
                </p>
            )}

            {pending && (
                <section
                    role="alertdialog"
                    aria-labelledby="delete-title"
                    aria-describedby="delete-description"
                    className="mb-6 rounded-card border border-red-300 bg-red-50 p-4 text-sm text-red-900"
                >
                    <h2 id="delete-title" className="text-base font-bold">
                        Delete {pending.asset.original_name ?? `image ${pending.asset.id}`}?
                    </h2>
                    <div id="delete-description">
                        {pending.usage.length === 0 ? (
                            <p className="mt-2">This image is not used by any post or page.</p>
                        ) : (
                            <>
                                <p className="mt-2">
                                    This image is used by {pending.usage.length} {pending.usage.length === 1 ? 'item' : 'items'}. Deleting
                                    it removes it as their hero image; images in the body will stop showing.
                                </p>
                                <ul className="mt-2 list-disc pl-5">
                                    {pending.usage.map((item) => (
                                        <li key={`${item.type}-${item.id}`}>
                                            <a href={item.edit_url} className="underline">
                                                {usageLabel(item)}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>
                    <div className="mt-3 flex gap-2">
                        <button type="button" className="button-danger" onClick={confirmDelete}>
                            {pending.usage.length > 0 ? 'Delete anyway' : 'Delete image'}
                        </button>
                        <button type="button" className="button-secondary" onClick={() => setPending(null)}>
                            Cancel
                        </button>
                    </div>
                </section>
            )}

            {assets.data.length === 0 ? (
                <p className="rounded-control border border-border bg-surface p-6 text-muted">
                    {search ? 'No images match your search.' : 'No images uploaded yet. Upload one from the hero image section of a post or page.'}
                </p>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {assets.data.map((asset) => (
                        <li key={asset.id} className="overflow-hidden rounded-card border border-border bg-surface">
                            <img
                                src={asset.thumbnail_url}
                                alt={asset.alt || asset.original_name || `Uploaded image ${asset.id}`}
                                className="aspect-video w-full object-cover"
                                loading="lazy"
                            />
                            <div className="flex flex-col gap-1 p-3 text-sm">
                                <p className="truncate font-semibold">{asset.original_name ?? `Image ${asset.id}`}</p>
                                <p className="text-xs text-muted">
                                    {asset.width} x {asset.height} · {formatDate(asset.created_at)}
                                    {asset.uploaded_by ? ` · ${asset.uploaded_by}` : ''}
                                </p>
                                <p className="text-xs text-muted">
                                    {asset.status === 'pending' && 'Processing. '}
                                    {asset.status === 'failed' && 'Processing failed. '}
                                    {asset.usage_count ? `Used by ${asset.usage_count}` : 'Not used'}
                                </p>
                                <button
                                    type="button"
                                    className="button-secondary mt-2 self-start"
                                    onClick={() => void askToDelete(asset)}
                                    aria-label={`Delete ${asset.original_name ?? `image ${asset.id}`}`}
                                >
                                    Delete
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <div className="mt-6">
                <Pagination page={assets} label="Media library" />
            </div>
        </WorkspaceLayout>
    );
}
