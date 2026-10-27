import { useEffect, useRef, type RefObject } from 'react';

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function focusableWithin(container: HTMLElement): HTMLElement[] {
    return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
        (element) => !element.hasAttribute('hidden') && element.getAttribute('aria-hidden') !== 'true',
    );
}

/**
 * Modal focus handling: moves focus into the container when it opens, keeps
 * Tab and Shift+Tab inside it, closes on Escape and gives focus back to
 * whatever had it before (or `fallbackFocus`) when it closes.
 */
export function useDialogFocus(
    open: boolean,
    containerRef: RefObject<HTMLElement | null>,
    onClose: () => void,
    options: { initialFocus?: string; fallbackFocus?: RefObject<HTMLElement | null>; lockScroll?: boolean } = {},
) {
    const onCloseRef = useRef(onClose);
    const optionsRef = useRef(options);

    useEffect(() => {
        onCloseRef.current = onClose;
        optionsRef.current = options;
    });

    useEffect(() => {
        if (!open) return;

        const container = containerRef.current;
        const { initialFocus, fallbackFocus, lockScroll } = optionsRef.current;
        const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const previousOverflow = document.body.style.overflow;
        if (lockScroll) document.body.style.overflow = 'hidden';

        const initial = (initialFocus ? container?.querySelector<HTMLElement>(initialFocus) : null) ?? (container ? focusableWithin(container)[0] : null);
        initial?.focus();

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onCloseRef.current();
                return;
            }
            if (event.key !== 'Tab' || !container) return;

            const elements = focusableWithin(container);
            if (elements.length === 0) {
                event.preventDefault();
                return;
            }

            const first = elements[0];
            const last = elements[elements.length - 1];
            const active = document.activeElement;

            if (event.shiftKey && (active === first || !container.contains(active))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (active === last || !container.contains(active))) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            if (lockScroll) document.body.style.overflow = previousOverflow;
            if (previouslyFocused?.isConnected) previouslyFocused.focus();
            else fallbackFocus?.current?.focus();
        };
    }, [open, containerRef]);
}
