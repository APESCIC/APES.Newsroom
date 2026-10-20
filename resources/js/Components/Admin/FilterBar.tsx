import { router } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

type FilterValues = Record<string, string>;

/**
 * Submits non-empty filter values as query parameters on the current list
 * page, keeping scroll and component state.
 */
export default function FilterBar({
    action,
    values,
    label,
    children,
}: {
    action: string;
    values: FilterValues;
    label: string;
    children: ReactNode;
}) {
    function submit(event: FormEvent) {
        event.preventDefault();
        const query = Object.fromEntries(Object.entries(values).filter(([, value]) => value.trim() !== ''));
        router.get(action, query, { preserveState: true, replace: true });
    }

    return (
        <form role="search" aria-label={label} className="glass-form-panel flex flex-col gap-3" onSubmit={submit}>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{children}</div>
            <div className="flex flex-wrap gap-3">
                <button type="submit" className="button-primary w-fit">
                    Apply filters
                </button>
                <button
                    type="button"
                    className="button-secondary w-fit"
                    onClick={() => router.get(action, {}, { preserveState: false, replace: true })}
                >
                    Clear
                </button>
            </div>
        </form>
    );
}
