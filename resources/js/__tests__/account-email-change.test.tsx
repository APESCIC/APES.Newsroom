import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import EmailChange from '../Pages/Account/EmailChange';
import Profile from '../Pages/Account/Profile';
import { getInertiaMock } from '../test/inertia';

const user = {
    name: 'Public Reader',
    email: 'reader@example.test',
    role: 'public',
    auth_provider: 'password',
};

const membership = {
    status: 'free',
    status_label: 'Free member',
    is_paying: false,
    interval: null,
    current_period_end: null,
    has_stripe_customer: false,
};

describe('pending email change on the account page', () => {
    it('explains the current address stays in use and offers resend and cancel', () => {
        render(
            <Profile
                user={user}
                membership={membership}
                pending_email={{ email: 'new@example.test', expires_at: '2026-10-12T11:00:00+00:00' }}
                can_delete_account
                deletion_block_reason={null}
            />,
        );

        expect(screen.getByText(/Waiting for you to confirm/)).toHaveTextContent('new@example.test');

        fireEvent.click(screen.getByRole('button', { name: 'Resend link' }));
        expect(getInertiaMock().post).toHaveBeenCalledWith('/account/email/resend', {}, { preserveScroll: true });

        fireEvent.click(screen.getByRole('button', { name: 'Cancel change' }));
        expect(getInertiaMock().delete).toHaveBeenCalledWith('/account/email/pending', { preserveScroll: true });
    });

    it('shows no notice without a pending change', () => {
        render(<Profile user={user} membership={membership} can_delete_account deletion_block_reason={null} />);

        expect(screen.queryByText(/Waiting for you to confirm/)).not.toBeInTheDocument();
    });
});

describe('email change link page', () => {
    it('confirms only when the button is pressed', () => {
        render(<EmailChange state="confirm" new_email="n***@example.test" action_url="/account/email-change/1/confirm?token=t&signature=s" />);

        expect(getInertiaMock().post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Confirm new email address' }));

        expect(getInertiaMock().post).toHaveBeenCalledWith('/account/email-change/1/confirm?token=t&signature=s', {}, expect.any(Object));
    });

    it('offers a cancel button from the old address link', () => {
        render(<EmailChange state="cancel" new_email="n***@example.test" action_url="/account/email-change/1/cancel?signature=s" />);

        fireEvent.click(screen.getByRole('button', { name: 'Cancel this change' }));

        expect(getInertiaMock().post).toHaveBeenCalledWith('/account/email-change/1/cancel?signature=s', {}, expect.any(Object));
    });

    it('explains why a link no longer works', () => {
        render(<EmailChange state="unavailable" new_email="n***@example.test" action_url={null} message="This email change has expired." />);

        expect(screen.getByRole('heading', { name: 'This link can no longer be used' })).toBeInTheDocument();
        expect(screen.getByText('This email change has expired.')).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
