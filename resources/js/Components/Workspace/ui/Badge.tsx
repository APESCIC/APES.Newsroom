import type { ReactNode } from 'react';

export type BadgeTone = 'ok' | 'warning' | 'error' | 'info' | 'neutral';

/** Status pill: green ok, amber warning, red error, teal info. */
export default function Badge({ tone = 'neutral', className = '', children }: { tone?: BadgeTone; className?: string; children: ReactNode }) {
    return <span className={`sg-badge sg-badge-${tone} ${className}`.trim()}>{children}</span>;
}
