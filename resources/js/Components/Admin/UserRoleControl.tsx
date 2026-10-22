import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import type { UserDetail } from '../../Pages/Admin/Users/types';
import ReasonConfirmDialog from './ReasonConfirmDialog';

/**
 * Super-admin control for accounts outside Cloudron SSO. Only demotion to
 * Public is possible in-app; elevated roles come from directory groups.
 */
export default function UserRoleControl({ user }: { user: UserDetail }) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const close = useCallback(() => {
        setOpen(false);
        setError(undefined);
    }, []);

    if (user.role_source.type === 'directory') {
        return <p className="text-sm text-muted">This role comes from Cloudron directory groups and can only be changed in Cloudron.</p>;
    }

    if (!user.can.change_role) {
        return <p className="text-sm text-muted">Only a super admin can change this account's role.</p>;
    }

    if (user.role === 'public') {
        return (
            <p className="text-sm text-muted">
                Staff and admin roles need Cloudron SSO. Add the person to the right directory group in Cloudron instead.
            </p>
        );
    }

    function confirm(reason: string) {
        setProcessing(true);
        router.patch(
            `/admin/users/${user.id}/role`,
            { role: 'public', reason },
            {
                preserveScroll: true,
                onSuccess: () => close(),
                onError: (errors: Record<string, string>) => setError(errors.role ?? errors.reason ?? Object.values(errors)[0]),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <>
            <button type="button" className="button-danger w-fit" onClick={() => setOpen(true)}>
                Change role to Public
            </button>
            <ReasonConfirmDialog
                open={open}
                title="Remove this account's elevated role?"
                description={`${user.name} will become a Public member, lose any Admin API tokens and be signed out everywhere.`}
                confirmLabel="Change role to Public"
                destructive
                processing={processing}
                error={error}
                onConfirm={confirm}
                onCancel={close}
            />
        </>
    );
}
