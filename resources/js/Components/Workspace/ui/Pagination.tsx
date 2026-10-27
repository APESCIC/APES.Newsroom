import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

export type PageMeta = {
    current_page: number;
    last_page: number;
    per_page?: number;
    total?: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

/** Previous / next pager for Laravel paginators. Hidden when there is one page. */
export default function Pagination({ page, label }: { page: PageMeta; label: string }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav className="flex flex-wrap items-center justify-between gap-3" aria-label={`${label} pagination`}>
            {page.prev_page_url ? (
                <Link href={page.prev_page_url} preserveState className="sg-btn sg-btn-secondary sg-btn-sm" rel="prev">
                    <ChevronLeft aria-hidden="true" className="h-4 w-4" />
                    Previous
                </Link>
            ) : (
                <span className="sg-btn sg-btn-secondary sg-btn-sm opacity-50" aria-disabled="true">
                    <ChevronLeft aria-hidden="true" className="h-4 w-4" />
                    Previous
                </span>
            )}
            <span className="text-sm text-muted">
                Page {page.current_page} of {page.last_page}
            </span>
            {page.next_page_url ? (
                <Link href={page.next_page_url} preserveState className="sg-btn sg-btn-secondary sg-btn-sm" rel="next">
                    Next
                    <ChevronRight aria-hidden="true" className="h-4 w-4" />
                </Link>
            ) : (
                <span className="sg-btn sg-btn-secondary sg-btn-sm opacity-50" aria-disabled="true">
                    Next
                    <ChevronRight aria-hidden="true" className="h-4 w-4" />
                </span>
            )}
        </nav>
    );
}
