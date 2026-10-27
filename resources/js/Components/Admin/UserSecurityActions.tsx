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
    const access: SecurityAction[] = user.locked
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
                  label: 'Lock (suspend) account',
                  title: 'Lock (suspend) this account?',
                  description: `${user.name} will be signed out everywhere and blocked from every sign-in method and API token until unlocked. They will be told by email.`,
                  confirmLabel: 'Lock account',
                  url: `${base}/lock`,
                  destructive: true,
              },
          ];

    const credentials: SecurityAction[] = [
        {
            key: 'sign-out',
            label: 'Sign out everywhere',
            title: 'Sign out of every session?',
            description: `Every browser and remembered device signed in as ${user.name} will be signed out.`,
            confirmLabel: 'Sign out everywhere',
            url: `${base}/sign-out`,
            destructive: false,
        },
    ];

    if (user.can.resend_verification) {
        credentials.push({
            key: 'verification',
            label: 'Resend verification email',
            title: 'Resend the verification email?',
            description: `A new link to verify ${user.email} is sent to that address.`,
            confirmLabel: 'Send email',
            url: `${base}/verification`,
            destructive: false,
        });
    }

    if (!user.sso_managed && user.sign_in_method === 'password') {
        credentials.push({
            key: 'password-reset',
            label: 'Force password reset',
            title: 'Force a password reset?',
            description: `The current password stops working, ${user.name} is signed out everywhere, and a reset link is emailed to them.`,
            confirmLabel: 'Force password reset',
            url: `${base}/password-reset`,
            destructive: true,
        });
    }

    if (!user.sso_managed && user.two_factor_enabled) {
        credentials.push({
            key: 'two-factor-reset',
            label: 'Turn off two-factor',
            title: 'Turn off two-factor sign-in?',
            description: 'The authenticator app and recovery codes stop working. Use this when the user has lost their device.',
            confirmLabel: 'Turn off two-factor',
            url: `${base}/two-factor/reset`,
            destructive: true,
        });
    }

    if (!user.sso_managed && user.passkeys.length > 0) {
        credentials.push({
            key: 'passkeys-remove',
            label: 'Remove passkeys',
            title: `Remove ${user.passkeys.length} passkey${user.passkeys.length === 1 ? '' : 's'}?`,
            description: 'Every passkey on the account is deleted and can no longer be used to sign in.',
            confirmLabel: 'Remove passkeys',
            url: `${base}/passkeys/remove`,
            destructive: true,
        });
    }

    return [...access, ...credentials];
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

    const ssoNote = user.sso_managed ? (
        <p className="text-sm text-muted">Password, two-factor and passkeys for this account are managed by Cloudron SSO.</p>
    ) : null;

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
            {ssoNote}
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
