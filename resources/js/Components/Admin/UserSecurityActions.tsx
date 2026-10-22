import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import ReasonConfirmDialog from './ReasonConfirmDialog';
import type { UserDetail } from '../../Pages/Admin/Users/types';

export type SecurityAction = {
    key: string;
    label: string;
    title: string;
    description: string;
    confirmLabel: string;
    url: string;
    destructive: boolean;
};

export function availableActions(user: UserDetail): SecurityAction[] {
    if (!user.can.manage_security) return [];

    const base = `/admin/users/${user.id}`;

    return user.locked
        ? [
              {
                  key: 'unlock',
                  label: 'Unlock account',
                  title: 'Unlock this account?',
                  description: `${user.name} will be able to sign in again and will be told by email.`,
                  confirmLabel: 'Unlock account',
                  url: `${base}/unlock`,
                  destructive: false,
              },
          ]
        : [
              {
                  key: 'lock',
                  label: 'Lock account',
                  title: 'Lock this account?',
                  description: `${user.name} will be signed out everywhere and blocked from every sign-in method and API token until unlocked. They will be told by email.`,
                  confirmLabel: 'Lock account',
                  url: `${base}/lock`,
                  destructive: true,
              },
          ];
}

export default function UserSecurityActions({ user }: { user: UserDetail }) {
    const [active, setActive] = useState<SecurityAction | null>(null);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    const actions = availableActions(user);
    const close = useCallback(() => {
        setActive(null);
        setError(undefined);
    }, []);

    if (actions.length === 0) {
        return <p className="text-sm text-muted">You cannot change security settings for this account.</p>;
    }

    function confirm(reason: string) {
        if (!active) return;
        setProcessing(true);
        router.post(
            active.url,
            { reason },
            {
                preserveScroll: true,
                onSuccess: () => close(),
                onError: (errors: Record<string, string>) => setError(errors.reason ?? Object.values(errors)[0]),
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <>
            <div className="flex flex-wrap gap-3">
                {actions.map((action) => (
                    <button
                        key={action.key}
                        type="button"
                        className={action.destructive ? 'button-danger' : 'button-secondary'}
                        onClick={() => setActive(action)}
                    >
                        {action.label}
                    </button>
                ))}
            </div>
            <ReasonConfirmDialog
                open={active !== null}
                title={active?.title ?? ''}
                description={active?.description ?? ''}
                confirmLabel={active?.confirmLabel ?? ''}
                destructive={active?.destructive}
                processing={processing}
                error={error}
                onConfirm={confirm}
                onCancel={close}
            />
        </>
    );
}
