import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ConfirmIdentity from '../Pages/Account/ConfirmIdentity';
import Security from '../Pages/Account/Security';
import { getInertiaMock } from '../test/inertia';

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
