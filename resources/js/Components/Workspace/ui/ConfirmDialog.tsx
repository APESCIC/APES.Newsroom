import { useId, useRef, type ReactNode } from 'react';
import { useDialogFocus } from '../useDialogFocus';

/** Modal yes/no confirmation for destructive or irreversible actions. */
export default function ConfirmDialog({
    open,
    title,
    description,
    confirmLabel,
    destructive = false,
    processing = false,
    children,
    onConfirm,
    onCancel,
}: {
    open: boolean;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    processing?: boolean;
    children?: ReactNode;
    onConfirm: () => void;
    onCancel: () => void;
}) {
    const titleId = useId();
    const descriptionId = useId();
    const dialogRef = useRef<HTMLDivElement>(null);

    useDialogFocus(open, dialogRef, onCancel, { initialFocus: '[data-autofocus]' });

    if (!open) return null;

    return (
        <div className="sg-overlay flex items-center justify-center p-4" onClick={onCancel}>
            <div
                ref={dialogRef}
                role="alertdialog"
                aria-modal="true"
                aria-labelledby={titleId}
                aria-describedby={descriptionId}
                className="sg-dialog w-full max-w-md p-6"
                onClick={(event) => event.stopPropagation()}
            >
                <h2 id={titleId} className="text-lg font-semibold text-body">
                    {title}
                </h2>
                <div id={descriptionId} className="mt-2 text-sm text-muted">
                    {description}
                </div>
                {children}
                <div className="mt-6 flex flex-wrap justify-end gap-3">
                    <button type="button" className="sg-btn sg-btn-secondary" onClick={onCancel} data-autofocus>
                        Cancel
                    </button>
                    <button type="button" className={`sg-btn ${destructive ? 'sg-btn-danger' : 'sg-btn-primary'}`} disabled={processing} onClick={onConfirm}>
                        {confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}
