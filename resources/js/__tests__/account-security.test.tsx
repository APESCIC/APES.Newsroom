import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ConfirmIdentity from '../Pages/Account/ConfirmIdentity';
import Security, { type AccountSession } from '../Pages/Account/Security';
import Login from '../Pages/Auth/Login';
import TwoFactorChallenge from '../Pages/Auth/TwoFactorChallenge';
import { getInertiaMock, setMockPage } from '../test/inertia';

describe('account security page', () => {
    it('asks password users for their current password', () => {
        render(<Security sso_managed={false} has_password />);

        expect(screen.getByRole('heading', { name: 'Change password' })).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Current password'), { target: { value: 'old-password-value' } });
        fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'a-much-longer-password' } });
        fireEvent.change(screen.getByLabelText('Confirm new password'), { target: { value: 'a-much-longer-password' } });
        fireEvent.click(screen.getByRole('button', { name: 'Change password' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/account/password');
        expect(visit?.data).toMatchObject({ current_password: 'old-password-value', password: 'a-much-longer-password' });
    });

    it('explains when the security policy requires two-factor sign-in', () => {
        const { unmount } = render(<Security sso_managed={false} has_password two_factor_required status="two-factor-required" />);

        expect(screen.getByRole('alert')).toHaveTextContent('Two-factor sign-in is required for your account.');
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        unmount();

        render(<Security sso_managed={false} has_password />);
        expect(screen.queryByText('Two-factor sign-in is required for your account.')).not.toBeInTheDocument();
    });

    it('lets magic-link users set a first password without a current one', () => {
        render(<Security sso_managed={false} has_password={false} />);

        expect(screen.getByRole('heading', { name: 'Set a password' })).toBeInTheDocument();
        expect(screen.queryByLabelText('Current password')).not.toBeInTheDocument();
    });

    it('hides the password form for SSO-managed accounts', () => {
        render(<Security sso_managed has_password={false} />);

        expect(screen.queryByLabelText('New password')).not.toBeInTheDocument();
        expect(screen.getByText(/APES staff sign-in/)).toBeInTheDocument();
    });
});

describe('two-factor setup', () => {
    it('offers setup when two-factor is off', () => {
        render(<Security sso_managed={false} has_password two_factor={{ enabled: false, recovery_codes_remaining: 0, setup: null }} />);

        fireEvent.click(screen.getByRole('button', { name: 'Set up two-factor' }));

        expect(getInertiaMock().post).toHaveBeenCalledWith('/account/two-factor', {}, { preserveScroll: true });
    });

    it('shows the QR code and key, then confirms with a code', () => {
        render(
            <Security
                sso_managed={false}
                has_password
                two_factor={{
                    enabled: false,
                    recovery_codes_remaining: 0,
                    setup: { qr_image: 'data:image/svg+xml;base64,PHN2Zy8+', secret: 'ABCDEFGHIJKLMNOP' },
                }}
            />,
        );

        expect(screen.getByAltText('QR code for your authenticator app')).toHaveAttribute('src', 'data:image/svg+xml;base64,PHN2Zy8+');
        expect(screen.getByText('ABCD EFGH IJKL MNOP')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Code from your app'), { target: { value: '123456' } });
        fireEvent.click(screen.getByRole('button', { name: 'Turn on two-factor' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/account/two-factor/confirm');
        expect(visit?.data).toMatchObject({ code: '123456' });
    });

    it('shows recovery codes once with a download option', () => {
        render(
            <Security
                sso_managed={false}
                has_password
                status="two-factor-enabled"
                two_factor={{ enabled: true, recovery_codes_remaining: 8, setup: null }}
                recovery_codes={['abcde-fghjk', 'mnpqr-stuvw']}
            />,
        );

        expect(screen.getByRole('status')).toHaveTextContent('Two-factor authentication is on.');
        expect(screen.getByRole('list', { name: 'Recovery codes' })).toHaveTextContent('abcde-fghjk');
        expect(screen.getByRole('button', { name: 'Download codes' })).toBeInTheDocument();
        expect(screen.getByText('8 recovery codes left.')).toBeInTheDocument();
    });

    it('hides two-factor for SSO-managed accounts', () => {
        render(<Security sso_managed has_password={false} two_factor={null} />);

        expect(screen.queryByRole('heading', { name: 'Two-factor authentication' })).not.toBeInTheDocument();
    });
});

describe('account sessions list', () => {
    const sessions: AccountSession[] = [
        {
            id: 1,
            browser: 'Chrome',
            platform: 'Windows',
            device_type: 'desktop',
            ip: '198.51.100.x',
            last_active_at: '2026-10-12T10:00:00+00:00',
            remembered: false,
            current: true,
        },
        {
            id: 2,
            browser: 'Mobile Safari',
            platform: 'iOS',
            device_type: 'smartphone',
            ip: '203.0.113.x',
            last_active_at: '2026-10-11T08:30:00+00:00',
            remembered: true,
            current: false,
        },
    ];

    it('shows each device with a marker for this one', () => {
        render(<Security sso_managed={false} has_password sessions={sessions} />);

        expect(screen.getByText('This device')).toBeInTheDocument();
        expect(screen.getByText(/Mobile Safari on iOS/)).toBeInTheDocument();
        expect(screen.getByText(/IP 203\.0\.113\.x/)).toBeInTheDocument();
        expect(screen.getByText(/Remembered/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sign out Chrome on Windows' })).not.toBeInTheDocument();
    });

    it('signs out a single session', () => {
        render(<Security sso_managed={false} has_password sessions={sessions} />);

        fireEvent.click(screen.getByRole('button', { name: 'Sign out Mobile Safari on iOS' }));

        expect(getInertiaMock().delete).toHaveBeenCalledWith('/account/sessions/2', { preserveScroll: true });
    });

    it('signs out all other sessions', () => {
        render(<Security sso_managed={false} has_password sessions={sessions} />);

        fireEvent.click(screen.getByRole('button', { name: 'Sign out all other sessions' }));

        expect(getInertiaMock().delete).toHaveBeenCalledWith('/account/sessions/others', { preserveScroll: true });
    });

    it('hides "sign out all" when this is the only session', () => {
        render(<Security sso_managed={false} has_password sessions={[sessions[0]]} />);

        expect(screen.queryByRole('button', { name: 'Sign out all other sessions' })).not.toBeInTheDocument();
    });
});

describe('login remember me', () => {
    it('sends the remember choice with the sign-in', () => {
        render(<Login staffLoginUrl={null} staffLoginLabel={null} rememberDays={30} />);

        fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'reader@example.com' } });
        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret-value' } });
        fireEvent.click(screen.getByLabelText('Keep me signed in on this device for 30 days'));
        fireEvent.click(screen.getByRole('button', { name: 'Log in' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/login');
        expect(visit?.data).toMatchObject({ email: 'reader@example.com', remember: true });
    });
});

describe('login after an abandoned challenge', () => {
    it('shows the reason the user was sent back', () => {
        const original = getInertiaMock().page.props;
        setMockPage({ ...original, errors: { email: 'Your sign-in has expired. Please sign in again.' } });

        try {
            render(<Login staffLoginUrl={null} staffLoginLabel={null} />);

            expect(screen.getByText('Your sign-in has expired. Please sign in again.')).toBeInTheDocument();
        } finally {
            setMockPage(original);
        }
    });
});

describe('two-factor challenge page', () => {
    it('sends the authentication code', () => {
        render(<TwoFactorChallenge />);

        fireEvent.change(screen.getByLabelText('Authentication code'), { target: { value: '123456' } });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/two-factor-challenge');
        expect(visit?.data).toMatchObject({ code: '123456' });
    });

    it('switches to a recovery code', () => {
        render(<TwoFactorChallenge />);

        fireEvent.click(screen.getByRole('button', { name: 'Use a recovery code instead' }));
        expect(screen.queryByLabelText('Authentication code')).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Recovery code'), { target: { value: 'abcde-fghjk' } });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/two-factor-challenge');
        expect(visit?.data).toMatchObject({ code: '', recovery_code: 'abcde-fghjk' });

        fireEvent.click(screen.getByRole('button', { name: 'Use your authenticator app instead' }));
        expect(screen.getByLabelText('Authentication code')).toBeInTheDocument();
    });
});

describe('managing two-factor', () => {
    const enabled = { enabled: true, recovery_codes_remaining: 8, setup: null };

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('gets new recovery codes after confirmation', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        render(<Security sso_managed={false} has_password two_factor={enabled} />);

        fireEvent.click(screen.getByRole('button', { name: 'Get new recovery codes' }));

        expect(getInertiaMock().post).toHaveBeenCalledWith('/account/two-factor/recovery-codes', {}, { preserveScroll: true });
    });

    it('turns two-factor off after confirmation', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        render(<Security sso_managed={false} has_password two_factor={enabled} />);

        fireEvent.click(screen.getByRole('button', { name: 'Turn off two-factor' }));

        expect(getInertiaMock().delete).toHaveBeenCalledWith('/account/two-factor', { preserveScroll: true });
    });

    it('does nothing when the confirmation is dismissed', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        render(<Security sso_managed={false} has_password two_factor={enabled} />);

        fireEvent.click(screen.getByRole('button', { name: 'Turn off two-factor' }));
        fireEvent.click(screen.getByRole('button', { name: 'Get new recovery codes' }));

        expect(getInertiaMock().delete).not.toHaveBeenCalled();
        expect(getInertiaMock().post).not.toHaveBeenCalled();
    });

    it('warns when recovery codes are running low', () => {
        render(<Security sso_managed={false} has_password status="recovery-code-used" two_factor={{ ...enabled, recovery_codes_remaining: 1 }} />);

        expect(screen.getByRole('status')).toHaveTextContent('You signed in with a recovery code.');
        expect(screen.getByText('1 recovery code left.')).toBeInTheDocument();
        expect(screen.getByText(/running low on recovery codes/)).toBeInTheDocument();
    });

    it('cancels a pending setup', () => {
        render(
            <Security
                sso_managed={false}
                has_password
                two_factor={{ enabled: false, recovery_codes_remaining: 0, setup: { qr_image: 'data:image/svg+xml;base64,PHN2Zy8+', secret: 'ABCD' } }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Cancel setup' }));

        expect(getInertiaMock().delete).toHaveBeenCalledWith('/account/two-factor', { preserveScroll: true });
    });
});

describe('confirm identity page', () => {
    it('shows a password prompt for password accounts', () => {
        render(<ConfirmIdentity method="password" />);

        fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret-value' } });
        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(getInertiaMock().visits.at(-1)?.url).toBe('/account/confirm-identity/password');
    });

    it('offers an emailed code for magic-link accounts', () => {
        render(<ConfirmIdentity method="email_code" status="code-sent" />);

        expect(screen.getByRole('status')).toHaveTextContent('6-digit code');
        fireEvent.click(screen.getByRole('button', { name: 'Send a new code' }));

        expect(getInertiaMock().visits.at(-1)?.url).toBe('/account/confirm-identity/code/send');
    });

    it('sends SSO accounts back through APES sign-in', () => {
        render(<ConfirmIdentity method="sso" />);

        expect(screen.getByRole('link', { name: 'Sign in to APES again' })).toHaveAttribute('href', '/account/confirm-identity/sso');
    });
});
