import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import ConfirmDialog from './ui/ConfirmDialog';

export type ConfirmedAction = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    method: 'post' | 'delete';
    url: string;
    destructive?: boolean;
};

function send({ method, url }: ConfirmedAction, onFinish: () => void) {
    const options = { preserveScroll: true, onFinish };
    switch (method) {
        case 'post':
            router.post(url, {}, options);
            return;
        case 'delete':
            router.delete(url, options);
            return;
        default: {
            const unreachable: never = method;
            return unreachable;
        }
    }
}

/**
 * One confirmation dialog shared by a list's row actions (#356): call
 * `confirm(action)` from a menu item and render `dialog` once in the page.
 */
export function useConfirmedAction(): {
    confirm: (action: ConfirmedAction) => void;
    dialog: ReactNode;
} {
    const [pending, setPending] = useState<ConfirmedAction | null>(null);
    const [processing, setProcessing] = useState(false);

    const dialog = pending && (
        <ConfirmDialog
            open
            title={pending.title}
            description={pending.description}
            confirmLabel={pending.confirmLabel}
            destructive={pending.destructive ?? true}
            processing={processing}
            onCancel={() => setPending(null)}
            onConfirm={() => {
                setProcessing(true);
                send(pending, () => {
                    setProcessing(false);
                    setPending(null);
                });
            }}
        />
    );

    return { confirm: setPending, dialog };
}
