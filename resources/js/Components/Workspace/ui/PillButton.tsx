import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';

export type PillVariant = 'primary' | 'secondary' | 'danger' | 'ghost';

export function pillClass(variant: PillVariant = 'primary', size: 'md' | 'sm' = 'md', className = ''): string {
    return `sg-btn sg-btn-${variant} ${size === 'sm' ? 'sg-btn-sm' : ''} ${className}`.replace(/\s+/g, ' ').trim();
}

type CommonProps = {
    variant?: PillVariant;
    size?: 'md' | 'sm';
    icon?: LucideIcon;
    className?: string;
    children: ReactNode;
};

/** Pill-shaped button. Pass `href` to render an Inertia link instead. */
export default function PillButton({
    variant = 'primary',
    size = 'md',
    icon: Icon,
    className = '',
    children,
    href,
    ...props
}: CommonProps & { href?: string } & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className' | 'children'>) {
    const content = (
        <>
            {Icon && <Icon aria-hidden="true" className="h-4 w-4 shrink-0" />}
            {children}
        </>
    );

    if (href) {
        return (
            <Link href={href} className={pillClass(variant, size, className)}>
                {content}
            </Link>
        );
    }

    return (
        <button type="button" className={pillClass(variant, size, className)} {...props}>
            {content}
        </button>
    );
}
