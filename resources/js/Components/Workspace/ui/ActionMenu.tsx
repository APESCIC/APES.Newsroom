import { Link } from '@inertiajs/react';
import { MoreHorizontal, type LucideIcon } from 'lucide-react';
import { useEffect, useId, useLayoutEffect, useRef, useState, type KeyboardEvent } from 'react';
import { createPortal } from 'react-dom';

type MenuPosition = { top: number; right: number };

const GAP = 8;

function positionBelow(trigger: HTMLElement): MenuPosition {
    const rect = trigger.getBoundingClientRect();
    return { top: rect.bottom + GAP, right: Math.max(GAP, window.innerWidth - rect.right) };
}

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
 *
 * The open menu is portalled into the workspace shell so cards that clip
 * their content (tables) cannot cut it off; frosted cards also trap
 * `position: fixed`, so it cannot stay inside them.
 */
export default function ActionMenu({ label, actions, buttonLabel }: { label: string; actions: MenuAction[]; buttonLabel?: string }) {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState<MenuPosition>({ top: 0, right: GAP });
    const [container, setContainer] = useState<HTMLElement | null>(null);
    const menuId = useId();
    const rootRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const itemsRef = useRef<HTMLElement[]>([]);

    useEffect(() => {
        if (!open) return;
        itemsRef.current[0]?.focus();

        const closeOnOutside = (event: MouseEvent) => {
            const target = event.target as Node;
            if (!rootRef.current?.contains(target) && !menuRef.current?.contains(target)) setOpen(false);
        };
        document.addEventListener('mousedown', closeOnOutside);
        return () => document.removeEventListener('mousedown', closeOnOutside);
    }, [open]);

    useLayoutEffect(() => {
        if (!open) return;

        const place = () => {
            const trigger = buttonRef.current;
            const menu = menuRef.current;
            if (!trigger || !menu) return;
            const rect = trigger.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const flip = spaceBelow < menu.offsetHeight + GAP && rect.top > spaceBelow;
            setPosition({ top: flip ? rect.top - menu.offsetHeight - GAP : rect.bottom + GAP, right: Math.max(GAP, window.innerWidth - rect.right) });
        };

        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [open]);

    const toggle = () => {
        if (open) {
            setOpen(false);
            return;
        }
        const trigger = buttonRef.current;
        if (trigger) {
            setPosition(positionBelow(trigger));
            setContainer(trigger.closest<HTMLElement>('.workspace-shell') ?? document.body);
        }
        setOpen(true);
    };

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
            event.preventDefault();
            close();
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
                onClick={toggle}
            >
                {buttonLabel}
                <MoreHorizontal aria-hidden="true" className="h-4 w-4" />
            </button>
            {open &&
                container &&
                createPortal(
                    <div
                        ref={menuRef}
                        id={menuId}
                        role="menu"
                        aria-label={label}
                        className="sg-menu"
                        style={{ position: 'fixed', top: position.top, right: position.right }}
                        onKeyDown={onMenuKeyDown}
                    >
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
                    </div>,
                    container,
                )}
        </div>
    );
}
