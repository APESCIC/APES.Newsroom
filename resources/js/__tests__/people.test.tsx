import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import { EMPTY_CONTACT_DETAILS } from '../Components/Account/ContactDetailsFields';
import Show from '../Pages/Staff/People/Show';
import type { ContactPerson, UserPerson } from '../Pages/Staff/People/types';
import { getInertiaMock, setMockPage } from '../test/inertia';

const mailing = {
    contact_id: 7,
    email: 'reader@example.test',
    linked: true,
    suppressed: false,
    subscriptions: [
        { subscription_id: 3, newsletter_id: 2, label: 'Rescue diary', status: 'confirmed' as const, confirmed_at: null, unsubscribed_at: null, can_unsubscribe: true, can_subscribe: false },
        { subscription_id: null, newsletter_id: 4, label: 'Clinic news', status: null, confirmed_at: null, unsubscribed_at: null, can_unsubscribe: false, can_subscribe: true },
    ],
    consent_history: [],
};

function userPerson(can: Partial<UserPerson['can']> = {}): UserPerson {
    return {
        kind: 'user',
        user: {
            id: 5,
            name: 'Riley Reader',
            email: 'reader@example.test',
            role: 'public',
            sign_in_method: 'password',
            sso_managed: false,
            email_verified: true,
            locked: false,
            created_at: null,
            pending_email: null,
        },
        contact_details: EMPTY_CONTACT_DETAILS,
        membership: {
            status: 'free',
            status_label: 'Free member',
            access_label: 'Free member',
            is_paying: false,
            has_paid_access: false,
            plan_name: null,
            interval: null,
            current_period_end: null,
            stripe_linked: false,
            comp: null,
            note: can.edit_note ? { text: null, updated_at: null, updated_by: null } : null,
        },
        mailing,
        profile: null,
        can: {
            update: true,
            view_admin_user: false,
            manage_comp: false,
            edit_note: false,
            manage_subscriptions: false,
            suppress: false,
            update_profile: false,
            ...can,
        },
    };
}

describe('person page', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Sam Staff', email: 'sam@example.test', role: 'staff' },
                can: { accessStaff: true, accessAdmin: false },
            },
        });
    });

    it('shows staff only the controls their role allows', () => {
        render(<Show person={userPerson()} />);

        expect(screen.getByRole('button', { name: 'Edit details' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Grant complimentary access' })).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Internal member note')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Unsubscribe from Rescue diary' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Security and roles' })).not.toBeInTheDocument();
    });

    it('asks admins for a reason before unsubscribing a member', async () => {
        const user = userEvent.setup();
        render(<Show person={userPerson({ manage_comp: true, edit_note: true, manage_subscriptions: true, suppress: true, view_admin_user: true })} />);

        expect(screen.getByRole('button', { name: 'Grant complimentary access' })).toBeInTheDocument();
        expect(screen.getByLabelText('Internal member note')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Send confirmation for Clinic news' })).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Unsubscribe from Rescue diary' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Unsubscribe from Rescue diary?' });
        await user.type(within(dialog).getByLabelText('Reason (recorded in the audit log)'), 'Asked by phone');
        await user.click(within(dialog).getByRole('button', { name: 'Unsubscribe' }));

        expect(getInertiaMock().post).toHaveBeenCalledWith(
            '/staff/people/contacts/7/subscriptions/3/unsubscribe',
            expect.anything(),
            { reason: 'Asked by phone' },
        );
    });

    it('lets staff edit a subscriber but keeps delete for admins', () => {
        const person: ContactPerson = {
            kind: 'contact',
            contact: { id: 7, name: 'Sam Subscriber', email: 'sub@example.test', created_at: null },
            mailing: { ...mailing, linked: false },
            can: { update: true, delete: false, manage_subscriptions: true, suppress: true },
        };
        render(<Show person={person} />);

        expect(screen.getByRole('form', { name: 'Edit subscriber' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Suppress address' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Delete subscriber' })).not.toBeInTheDocument();
    });
});
