import type { ElementType, HTMLAttributes, ReactNode } from 'react';

type Padding = 'none' | 'sm' | 'md' | 'lg';

const paddings: Record<Padding, string> = {
    none: '',
    sm: 'p-4',
    md: 'p-5 sm:p-6',
    lg: 'p-6 sm:p-8',
};

/** Frosted surface for panel content. */
export default function GlassCard({
    as: Component = 'div',
    padding = 'md',
    className = '',
    title,
    description,
    actions,
    children,
    ...props
}: {
    as?: ElementType;
    padding?: Padding;
    className?: string;
    title?: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    children?: ReactNode;
} & Omit<HTMLAttributes<HTMLElement>, 'title'>) {
    return (
        <Component className={`sg-card ${paddings[padding]} ${className}`.trim()} {...props}>
            {(title || actions) && (
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        {title && <h2 className="text-base font-semibold text-body">{title}</h2>}
                        {description && <p className="mt-1 text-sm text-muted">{description}</p>}
                    </div>
                    {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
                </div>
            )}
            {children}
        </Component>
    );
}
