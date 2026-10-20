import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';

const FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Modal that asks an admin for a reason before a sensitive action. The
 * reason is required and is passed to `onConfirm`.
 */
export default function ReasonConfirmDialog({
    open,
    title,
    description,
    confirmLabel,
    destructive = false,
    processing = false,
    error,
    children,
    onConfirm,
    onCancel,
}: {
    open: boolean;
    title: string;
    description: string;
    confirmLabel: string;
    destructive?: boolean;
    processing?: boolean;
    error?: string;
    children?: ReactNode;
    onConfirm: (reason: string) => void;
    onCancel: () => void;
}) {
    const [reason, setReason] = useState('');
    const titleId = useId();
    const descriptionId = useId();
    const reasonId = useId();
    const dialogRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const dialog = dialogRef.current;
        dialog?.querySelector<HTMLElement>('textarea')?.focus();

        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onCancel();
                return;
            }
            if (event.key !== 'Tab' || !dialog) return;

            const elements = Array.from(dialog.querySelectorAll<HTMLElement>(FOCUSABLE));
            if (elements.length === 0) return;
            const first = elements[0];
            const last = elements[elements.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            previouslyFocused?.focus();
        };
    }, [open, onCancel]);

    useEffect(() => {
        if (!open) setReason('');
    }, [open]);

    if (!open) return null;

    function submit(event: FormEvent) {
        event.preventDefault();
        if (reason.trim() === '') return;
        onConfirm(reason.trim());
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/45 p-4" onClick={onCancel}>
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={descriptionId}
                className="workspace-glass-card w-full max-w-lg p-6"
                onClick={(event) => event.stopPropagation()}
            >
                <form className="flex flex-col gap-4" onSubmit={submit}>
                    <h2 id={titleId} className="text-lg font-bold text-body">
                        {title}
                    </h2>
                    <p id={descriptionId} className="text-sm text-muted">
                        {description}
                    </p>
                    {children}
                    <label htmlFor={reasonId} className="text-sm font-bold text-body">
                        Reason (recorded in the audit log)
                    </label>
                    <textarea
                        id={reasonId}
                        className="form-input"
                        rows={3}
                        maxLength={500}
                        required
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                    />
                    {error && (
                        <p className="text-sm text-danger" role="alert">
                            {error}
                        </p>
                    )}
                    <div className="flex flex-wrap justify-end gap-3">
                        <button type="button" className="button-secondary" onClick={onCancel}>
                            Cancel
                        </button>
                        <button
                            type="submit"
                            className={destructive ? 'button-danger' : 'button-primary'}
                            disabled={processing || reason.trim() === ''}
                        >
                            {confirmLabel}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
