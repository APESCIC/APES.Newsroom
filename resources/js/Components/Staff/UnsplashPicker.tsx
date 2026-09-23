import { useState } from 'react';

type UnsplashResult = {
    id: string;
    thumb: string;
    full: string;
    alt: string;
    credit: string;
    photographer: string;
};

type Props = {
    onSelect: (selection: { url: string; credit: string; alt: string }) => void;
};

export default function UnsplashPicker({ onSelect }: Props) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<UnsplashResult[]>([]);
    const [enabled, setEnabled] = useState<boolean | null>(null);
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);

    async function search() {
        setBusy(true);
        setMessage('');
        try {
            const response = await fetch(`/staff/media/unsplash?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            setEnabled(Boolean(payload.enabled));
            setResults(Array.isArray(payload.results) ? payload.results : []);
            if (!payload.enabled) {
                setMessage(payload.message || 'Unsplash is not configured.');
            }
        } catch {
            setMessage('Search failed.');
        } finally {
            setBusy(false);
        }
    }

    async function choose(photo: UnsplashResult) {
        setBusy(true);
        try {
            await fetch('/staff/media/unsplash/select', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                    ),
                },
                body: JSON.stringify({
                    id: photo.id,
                    url: photo.full,
                    credit: photo.credit,
                    alt: photo.alt,
                }),
            });
            onSelect({ url: photo.full, credit: photo.credit, alt: photo.alt });
        } finally {
            setBusy(false);
        }
    }

    if (enabled === false) {
        return null;
    }

    return (
        <div className="mt-3 rounded border border-edge/40 p-3">
            <p className="text-sm font-bold text-body">Unsplash search</p>
            <div className="mt-2 flex gap-2">
                <input
                    className="form-input flex-1"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Search photos"
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            void search();
                        }
                    }}
                />
                <button type="button" className="button-secondary" disabled={busy || !query.trim()} onClick={() => void search()}>
                    Search
                </button>
            </div>
            {message && <p className="mt-2 text-sm text-muted">{message}</p>}
            {results.length > 0 && (
                <ul className="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4">
                    {results.map((photo) => (
                        <li key={photo.id}>
                            <button
                                type="button"
                                className="block w-full overflow-hidden rounded border border-edge/30"
                                onClick={() => void choose(photo)}
                                title={photo.credit}
                            >
                                <img src={photo.thumb} alt={photo.alt || photo.photographer} className="aspect-square w-full object-cover" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
