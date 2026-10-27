import { useForm } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import ConfirmDialog from '../Workspace/ui/ConfirmDialog';
import FormField from '../Workspace/ui/FormField';
import PillButton, { type PillVariant } from '../Workspace/ui/PillButton';

type Payload = Record<string, string | number | boolean | null>;

/**
 * A button that opens a confirmation asking for the audit reason, then sends
 * the request. Every staff change to a person needs a reason (#567).
 */
export default function ReasonActionButton({
    label,
    ariaLabel,
    icon,
    variant = 'secondary',
    size = 'sm',
    title,
    description,
    confirmLabel,
    destructive = false,
    method = 'post',
    url,
    data = {},
    children,
}: {
    label: string;
    ariaLabel?: string;
    icon?: LucideIcon;
    variant?: PillVariant;
    size?: 'md' | 'sm';
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    method?: 'post' | 'patch' | 'delete';
    url: string;
    data?: Payload;
    children?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<Payload & { reason: string }>({ ...data, reason: '' });
    const otherErrors = Object.entries(form.errors).filter(([key]) => key !== 'reason');

    function close() {
        setOpen(false);
        form.reset();
        form.clearErrors();
    }

    function confirm() {
        form.submit(method, url, { preserveScroll: true, onSuccess: close });
    }

    return (
        <>
            <PillButton variant={variant} size={size} icon={icon} aria-label={ariaLabel} onClick={() => setOpen(true)}>
                {label}
            </PillButton>
            <ConfirmDialog
                open={open}
                title={title}
                description={description}
                confirmLabel={confirmLabel}
                destructive={destructive}
                processing={form.processing}
                onConfirm={confirm}
                onCancel={close}
            >
                <div className="mt-4 flex flex-col gap-3">
                    {children}
                    <FormField label="Reason (recorded in the audit log)" error={form.errors.reason}>
                        <textarea
                            className="form-input"
                            rows={3}
                            maxLength={500}
                            value={String(form.data.reason ?? '')}
                            onChange={(event) => form.setData('reason', event.target.value)}
                        />
                    </FormField>
                    {otherErrors.map(([key, message]) => (
                        <p key={key} className="text-sm font-medium text-danger" role="alert">
                            {message}
                        </p>
                    ))}
                </div>
            </ConfirmDialog>
        </>
    );
}
