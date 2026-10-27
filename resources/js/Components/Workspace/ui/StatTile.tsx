import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/** Frosted number tile. Wraps two to a row on phones (#520). */
export default function StatTile({
    label,
    value,
    hint,
    icon: Icon,
    href,
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    icon?: LucideIcon;
    href?: string;
}) {
    const body = (
        <>
            <span className="flex items-center justify-between gap-2 text-sm font-medium text-muted">
                {label}
                {Icon && <Icon aria-hidden="true" className="h-4 w-4 text-teal-deep" />}
            </span>
            <span className="sg-stat-value">{value}</span>
            {hint && <span className="text-xs text-muted">{hint}</span>}
        </>
    );

    if (href) {
        return (
            <Link href={href} className="sg-card sg-stat hover:shadow-lg">
                {body}
            </Link>
        );
    }

    return <div className="sg-card sg-stat">{body}</div>;
}

export function StatGrid({ label, children }: { label?: string; children: ReactNode }) {
    return (
        <div role={label ? 'group' : undefined} aria-label={label} className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {children}
        </div>
    );
}
