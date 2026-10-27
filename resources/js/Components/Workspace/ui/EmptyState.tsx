import { Inbox, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export default function EmptyState({
    icon: Icon = Inbox,
    title,
    description,
    action,
}: {
    icon?: LucideIcon;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="sg-card flex flex-col items-center px-6 py-10 text-center">
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-white/70 text-teal-deep">
                <Icon aria-hidden="true" className="h-6 w-6" />
            </span>
            <h2 className="mt-4 text-lg font-semibold text-body">{title}</h2>
            {description && <p className="mt-1 max-w-md text-sm text-muted">{description}</p>}
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}
