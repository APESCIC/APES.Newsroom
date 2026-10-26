import { useCallback, useEffect, useId, useRef, useState } from 'react';
import type { Paginator } from '../Admin/types';
import type { HeroMediaAsset } from './heroImageUpload';

type MediaLibraryPickerProps = {
    onPick: (asset: HeroMediaAsset) => void;
    disabled?: boolean;
};

/**
 * "Choose from library" button and dialog for reusing an uploaded image (#537).
 */
export default function MediaLibraryPicker({ onPick, disabled = false }: MediaLibraryPickerProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<Paginator<HeroMediaAsset> | null>(null);
    const [error, setError] = useState('');
    const titleId = useId();
    const searchRef = useRef<HTMLInputElement | null>(null);
    const openerRef = useRef<HTMLButtonElement | null>(null);

    const load = useCallback(async (search: string, pageNumber: number) => {
        setError('');
        try {
            const params = new URLSearchParams({ page: String(pageNumber) });
            if (search.trim() !== '') {
                params.set('q', search.trim());
            }
            const response = await fetch(`/staff/media/library?${params.toString()}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error();
            }
            setResult((await response.json()) as Paginator<HeroMediaAsset>);
        } catch {
            setError('The media library could not be loaded.');
        }
    }, []);

    const queryRef = useRef(query);
    queryRef.current = query;

    useEffect(() => {
        if (open) {
            void load(queryRef.current, page);
        }
    }, [open, page, load]);

    useEffect(() => {
        if (open) {
            searchRef.current?.focus();
        }
    }, [open]);

    const close = () => {
        setOpen(false);
        openerRef.current?.focus();
    };

    return (
        <>
            <button
                ref={openerRef}
                type="button"
                className="button-secondary"
                disabled={disabled}
                aria-haspopup="dialog"
                onClick={() => {
                    setPage(1);
                    setOpen(true);
                }}
            >
                Choose from library
            </button>
            {open && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={close}>
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby={titleId}
                        className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-card bg-white p-5 text-body shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                event.stopPropagation();
                                close();
                            }
                        }}
                    >
                        <div className="flex items-center justify-between gap-3">
                            <h2 id={titleId} className="text-lg font-bold">
                                Media library
                            </h2>
                            <button type="button" className="button-secondary" onClick={close}>
                                Close
                            </button>
                        </div>
                        <form
                            role="search"
                            className="mt-4 flex gap-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                setPage(1);
                                void load(query, 1);
                            }}
                        >
                            <label htmlFor={`${titleId}-search`} className="sr-only">
                                Search uploaded images
                            </label>
                            <input
                                id={`${titleId}-search`}
                                ref={searchRef}
                                className="form-input flex-1"
                                placeholder="Search by file name or alt text"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                            />
                            <button type="submit" className="button-secondary">
                                Search
                            </button>
                        </form>
                        {error && (
                            <p className="mt-3 text-sm text-red-600" role="alert">
                                {error}
                            </p>
                        )}
                        {result && result.data.length === 0 && <p className="mt-4 text-sm text-muted">No uploaded images found.</p>}
                        {result && result.data.length > 0 && (
                            <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {result.data.map((asset) => (
                                    <li key={asset.id}>
                                        <button
                                            type="button"
                                            className="block w-full overflow-hidden rounded-control border border-border text-left hover:border-teal-deep focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-focus)]"
                                            disabled={asset.status === 'failed'}
                                            onClick={() => {
                                                onPick(asset);
                                                close();
                                            }}
                                        >
                                            <img src={asset.thumbnail_url} alt="" className="aspect-video w-full object-cover" loading="lazy" />
                                            <span className="block truncate px-2 py-1 text-xs">
                                                Use {asset.alt || asset.original_name || `image ${asset.id}`}
                                                {asset.status === 'failed' ? ' (processing failed)' : ''}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {result && result.last_page > 1 && (
                            <nav className="mt-4 flex items-center justify-between text-sm" aria-label="Media library pages">
                                <button type="button" className="button-secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                                    Previous
                                </button>
                                <span className="text-muted">
                                    Page {result.current_page} of {result.last_page}
                                </span>
                                <button
                                    type="button"
                                    className="button-secondary"
                                    disabled={page >= result.last_page}
                                    onClick={() => setPage(page + 1)}
                                >
                                    Next
                                </button>
                            </nav>
                        )}
                    </div>
                </div>
            )}
        </>
    );
}
