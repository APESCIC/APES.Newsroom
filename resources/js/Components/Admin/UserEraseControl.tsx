import { router } from '@inertiajs/react';
import { useCallback, useId, useState } from 'react';
import ReasonConfirmDialog from './ReasonConfirmDialog';
import type { EraseMode, UserDetail } from '../../Pages/Admin/Users/types';

export function eraseExplanation(mode: EraseMode, name: string): string {
    switch (mode) {
        case 'deleted':
            return `${name}'s account, profile, comments and sessions are permanently deleted and their newsletter subscriptions are removed.`;
        case 'anonymised':
            return `${name} owns editorial or audit records, so the account is anonymised instead: name, email, contact details, sign-in methods and profile are removed, the account is locked, and their content stays under "Deleted user".`;
        default: {
            const unreachable: never = mode;
            return unreachable;
        }
    }
}

export default function UserEraseControl({ user }: { user: UserDetail }) {
    const [open, setOpen] = useState(false);
    const [confirmEmail, setConfirmEmail] = useState('');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const confirmId = useId();
    const close = useCallback(() => {
        setOpen(false);
        setConfirmEmail('');
        setError(undefined);
    }, []);

    if (!user.can.erase || user.erase_mode === null) {
        return <p className="text-sm text-muted">Only a super admin can delete this account.</p>;
    }

    const explanation = eraseExplanation(user.erase_mode, user.name);

    function confirm(reason: string) {
        setProcessing(true);
        router.delete(`/admin/users/${user.id}`, {
            data: { reason, confirm_email: confirmEmail },
            onError: (errors: Record<string, string>) => setError(errors.confirm_email ?? errors.reason ?? Object.values(errors)[0]),
            onFinish: () => setProcessing(false),
        });
    }

    return (
        <>
            <p className="text-sm text-body">{explanation}</p>
            <button type="button" className="button-danger w-fit" onClick={() => setOpen(true)}>
                Delete user
            </button>
            <ReasonConfirmDialog
                open={open}
                title="Delete this user?"
                description={`${explanation} This cannot be undone.`}
                confirmLabel={user.erase_mode === 'deleted' ? 'Delete user' : 'Anonymise user'}
                destructive
                processing={processing}
                error={error}
                onConfirm={confirm}
                onCancel={close}
            >
                <label htmlFor={confirmId} className="text-sm font-bold text-body">
                    Type {user.email} to confirm
                </label>
                <input
                    id={confirmId}
                    className="form-input"
                    autoComplete="off"
                    value={confirmEmail}
                    onChange={(event) => setConfirmEmail(event.target.value)}
                    required
                />
            </ReasonConfirmDialog>
        </>
    );
}
