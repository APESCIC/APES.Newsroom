import { Link } from '@inertiajs/react';
import type { Paginator } from './types';

export default function Pagination({ page, label }: { page: Paginator<unknown>; label: string }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav className="flex items-center justify-between gap-3 text-sm" aria-label={`${label} pagination`}>
            {page.prev_page_url ? (
                <Link href={page.prev_page_url} preserveState className="button-secondary min-h-11">
                    Previous
                </Link>
            ) : (
                <span className="text-muted">Previous</span>
            )}
            <span className="text-muted">
                Page {page.current_page} of {page.last_page}
            </span>
            {page.next_page_url ? (
                <Link href={page.next_page_url} preserveState className="button-secondary min-h-11">
                    Next
                </Link>
            ) : (
                <span className="text-muted">Next</span>
            )}
        </nav>
    );
}
