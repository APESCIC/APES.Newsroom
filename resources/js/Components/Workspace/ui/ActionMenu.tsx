import { Link } from '@inertiajs/react';
import { MoreHorizontal, type LucideIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';

export type MenuAction = {
    label: string;
    icon?: LucideIcon;
    tone?: 'default' | 'danger';
    href?: string;
    onSelect?: () => void;
    disabled?: boolean;
};

/**
 * Overflow menu. Destructive actions live here (or in a confirm dialog),
 * never inline beside Edit (#515).
 */
export default function ActionMenu({ label, actions, buttonLabel }: { label: string; actions: MenuAction[]; buttonLabel?: string }) {
    const [open, setOpen] = useState(false);
    const menuId = useId();
    const rootRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const itemsRef = useRef<HTMLElement[]>([]);

    useEffect(() => {
        if (!open) return;
        itemsRef.current[0]?.focus();

        const closeOnOutside = (event: MouseEvent) => {
            if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', closeOnOutside);
        return () => document.removeEventListener('mousedown', closeOnOutside);
    }, [open]);

    const close = (refocus = true) => {
        setOpen(false);
        if (refocus) buttonRef.current?.focus();
    };

    const onMenuKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const items = itemsRef.current.filter(Boolean);
        const index = items.indexOf(document.activeElement as HTMLElement);
        if (event.key === 'Escape') {
            event.preventDefault();
            close();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            items[(index + 1) % items.length]?.focus();
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            items[(index - 1 + items.length) % items.length]?.focus();
        } else if (event.key === 'Tab') {
            close(false);
        }
    };

    return (
        <div ref={rootRef} className="relative inline-block">
            <button
                ref={buttonRef}
                type="button"
                className={buttonLabel ? 'sg-btn sg-btn-secondary sg-btn-sm' : 'sg-icon-btn'}
                aria-label={buttonLabel ? undefined : label}
                aria-haspopup="menu"
                aria-expanded={open}
                aria-controls={open ? menuId : undefined}
                onClick={() => setOpen((value) => !value)}
            >
                {buttonLabel}
                <MoreHorizontal aria-hidden="true" className="h-4 w-4" />
            </button>
            {open && (
                <div id={menuId} role="menu" aria-label={label} className="sg-menu mt-2" onKeyDown={onMenuKeyDown}>
                    {actions.map((action, index) => {
                        const Icon = action.icon;
                        const content = (
                            <>
                                {Icon && <Icon aria-hidden="true" className="h-4 w-4" />}
                                {action.label}
                            </>
                        );
                        const ref = (element: HTMLElement | null) => {
                            if (element) itemsRef.current[index] = element;
                        };

                        return action.href ? (
                            <Link key={action.label} ref={ref} role="menuitem" href={action.href} className="sg-menu-item" data-tone={action.tone}>
                                {content}
                            </Link>
                        ) : (
                            <button
                                key={action.label}
                                ref={ref}
                                type="button"
                                role="menuitem"
                                className="sg-menu-item"
                                data-tone={action.tone}
                                disabled={action.disabled}
                                onClick={() => {
                                    // Refocus the trigger first so a dialog opened by the action returns focus to it.
                                    close();
                                    action.onSelect?.();
                                }}
                            >
                                {content}
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
