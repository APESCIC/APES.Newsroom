import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export type TabItem = {
    label: string;
    active: boolean;
    href?: string;
    onSelect?: () => void;
    count?: ReactNode;
};

/**
 * Frosted pill tabs. Link tabs filter a list page (aria-current); button
 * tabs switch panels on the same page (aria-pressed).
 */
export default function Tabs({ label, items, className = '' }: { label: string; items: TabItem[]; className?: string }) {
    return (
        <nav aria-label={label} className={`sg-tabs max-w-full ${className}`.trim()}>
            <ul className="flex gap-1 overflow-x-auto">
                {items.map((item) => {
                    const content = (
                        <>
                            {item.label}
                            {item.count !== undefined && <span className="sg-badge sg-badge-neutral">{item.count}</span>}
                        </>
                    );

                    return (
                        <li key={item.label}>
                            {item.href ? (
                                <Link href={item.href} aria-current={item.active ? 'page' : undefined} className="sg-tab">
                                    {content}
                                </Link>
                            ) : (
                                <button
                                    type="button"
                                    aria-pressed={item.active}
                                    aria-current={item.active ? 'page' : undefined}
                                    className="sg-tab"
                                    onClick={item.onSelect}
                                >
                                    {content}
                                </button>
                            )}
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
