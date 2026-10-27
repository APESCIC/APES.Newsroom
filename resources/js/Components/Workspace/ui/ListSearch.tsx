import { router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

/**
 * Search box for a paginated panel list. Keeps the other filters and puts
 * `q` in the query string so the URL can be shared or reloaded (#356).
 */
export default function ListSearch({
    path,
    label,
    placeholder,
    initial,
    params = {},
}: {
    path: string;
    label: string;
    placeholder?: string;
    initial: string;
    params?: Record<string, string | null | undefined>;
}) {
    const [q, setQ] = useState(initial);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const query: Record<string, string> = {};
        for (const [key, value] of Object.entries(params)) {
            if (value) query[key] = value;
        }
        if (q.trim()) query.q = q.trim();
        router.get(path, query, { preserveState: true, replace: true });
    };

    return (
        <form onSubmit={submit} className="sg-card grid gap-3 p-4 sm:grid-cols-[1fr_auto] sm:items-end" role="search" aria-label={label}>
            <label className="text-sm font-semibold text-body">
                Search
                <input type="search" value={q} onChange={(event) => setQ(event.target.value)} className="form-input mt-1.5" placeholder={placeholder} />
            </label>
            <button type="submit" className="sg-btn sg-btn-primary">
                Search
            </button>
        </form>
    );
}
