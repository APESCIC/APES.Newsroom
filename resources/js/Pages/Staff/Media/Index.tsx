import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Image as ImageIcon, Search, Trash2 } from 'lucide-react';
import { useState } from 'react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import type { HeroMediaAsset } from '../../../Components/Staff/heroImageUpload';
import Badge from '../../../Components/Workspace/ui/Badge';
import ConfirmDialog from '../../../Components/Workspace/ui/ConfirmDialog';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import Pagination, { type PageMeta } from '../../../Components/Workspace/ui/Pagination';

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

export default function MediaIndex({ assets, search }: { assets: PageMeta & { data: LibraryAsset[] }; search: string }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
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
                className="sg-card flex flex-col gap-2 p-4 sm:flex-row"
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
                <button type="submit" className="sg-btn sg-btn-primary">
                    <Search aria-hidden="true" className="h-4 w-4" />
                    Search
                </button>
            </form>

            {(errors?.confirm || loadError) && (
                <p className="sg-card border-l-4 !border-l-[var(--color-sg-error)] px-4 py-3 text-sm text-danger" role="alert">
                    {errors?.confirm ?? loadError}
                </p>
            )}

            {assets.data.length === 0 ? (
                <EmptyState
                    icon={ImageIcon}
                    title={search ? 'No images match your search' : 'No images uploaded yet'}
                    description={search ? 'Try another file name or alt text.' : 'Upload one from the hero image section of a post or page.'}
                />
            ) : (
                <ul className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    {assets.data.map((asset) => (
                        <li key={asset.id} className="sg-card flex flex-col overflow-hidden">
                            <img
                                src={asset.thumbnail_url}
                                alt={asset.alt || asset.original_name || `Uploaded image ${asset.id}`}
                                className="aspect-video w-full object-cover"
                                loading="lazy"
                            />
                            <div className="flex flex-1 flex-col gap-1 p-3 text-sm">
                                <p className="truncate font-semibold">{asset.original_name ?? `Image ${asset.id}`}</p>
                                <p className="text-xs text-muted">
                                    {asset.width} x {asset.height} · {formatDate(asset.created_at)}
                                    {asset.uploaded_by ? ` · ${asset.uploaded_by}` : ''}
                                </p>
                                <p className="flex flex-wrap gap-1 text-xs">
                                    {asset.status === 'pending' && <Badge tone="info">Processing</Badge>}
                                    {asset.status === 'failed' && <Badge tone="error">Processing failed</Badge>}
                                    <Badge tone={asset.usage_count ? 'ok' : 'neutral'}>{asset.usage_count ? `Used by ${asset.usage_count}` : 'Not used'}</Badge>
                                </p>
                                <button
                                    type="button"
                                    className="sg-btn sg-btn-ghost sg-btn-sm mt-auto self-start !text-danger"
                                    onClick={() => void askToDelete(asset)}
                                    aria-label={`Delete ${asset.original_name ?? `image ${asset.id}`}`}
                                >
                                    <Trash2 aria-hidden="true" className="h-4 w-4" />
                                    Delete
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <Pagination page={assets} label="Media library" />

            <ConfirmDialog
                open={pending !== null}
                title={`Delete ${pending?.asset.original_name ?? `image ${pending?.asset.id}`}?`}
                confirmLabel={pending && pending.usage.length > 0 ? 'Delete anyway' : 'Delete image'}
                destructive
                onCancel={() => setPending(null)}
                onConfirm={confirmDelete}
                description={
                    pending && pending.usage.length > 0 ? (
                        <>
                            <p>
                                This image is used by {pending.usage.length} {pending.usage.length === 1 ? 'item' : 'items'}. Deleting it removes it as their
                                hero image; images in the body will stop showing.
                            </p>
                            <ul className="mt-2 list-disc pl-5">
                                {pending.usage.map((item) => (
                                    <li key={`${item.type}-${item.id}`}>
                                        <a href={item.edit_url} className="text-body underline">
                                            {usageLabel(item)}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </>
                    ) : (
                        <p>This image is not used by any post or page.</p>
                    )
                }
            />
        </WorkspaceLayout>
    );
}
