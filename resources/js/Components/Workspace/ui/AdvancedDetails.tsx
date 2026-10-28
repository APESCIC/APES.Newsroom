import { ChevronRight, Wrench } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Collapsed "Advanced" disclosure for technical or test-mode details (#357).
 * Only render it with data the server already limited to admins.
 */
export default function AdvancedDetails({ summary, children, className = '' }: { summary: string; children: ReactNode; className?: string }) {
    return (
        <details className={`sg-card group p-4 ${className}`.trim()} data-testid="advanced-details">
            <summary className="flex cursor-pointer list-none items-center gap-2 rounded-full text-sm font-semibold text-body [&::-webkit-details-marker]:hidden">
                <ChevronRight aria-hidden="true" className="h-4 w-4 transition-transform group-open:rotate-90 motion-reduce:transition-none" />
                <Wrench aria-hidden="true" className="h-4 w-4 text-teal-deep" />
                Advanced: {summary}
                <span className="ml-auto text-xs font-medium text-muted">Admins only</span>
            </summary>
            <div className="mt-4 flex flex-col gap-3 text-sm text-body">{children}</div>
        </details>
    );
}
