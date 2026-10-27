import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import { EMPTY_CONTACT_DETAILS } from '../Components/Account/ContactDetailsFields';
import UsersEdit from '../Pages/Admin/Users/Edit';
import type { EditableUser } from '../Pages/Admin/Users/types';
import { getInertiaMock, setMockPage } from '../test/inertia';

const editable: EditableUser = {
    id: 9,
    name: 'Paula Password',
    email: 'paula@example.test',
    sso_managed: false,
    pending_email: null,
    contact_details: { ...EMPTY_CONTACT_DETAILS, mobile_number: '+447700900123' },
};

describe('admin user edit page', () => {
    beforeEach(() => {
        setMockPage({
            appName: 'APES Newsroom',
            auth: {
                user: { id: 1, name: 'Alex Admin', email: 'alex@example.test', role: 'admin' },
                can: { accessStaff: true, accessAdmin: true },
            },
        });
    });

    it('submits name, contact details and the reason', async () => {
        const user = userEvent.setup();
        render(<UsersEdit user={editable} />);

        const name = screen.getByLabelText('Name');
        await user.clear(name);
        await user.type(name, 'Paula P');
        const contact = within(screen.getByRole('region', { name: 'Contact details' }));
        expect(contact.getByLabelText(/^Mobile number/)).toHaveValue('+447700900123');
        await user.type(contact.getByLabelText('Town or city'), 'Leeds');
        await user.type(screen.getByLabelText('Reason (recorded in the audit log)'), 'Member phoned in');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/admin/users/9');
        expect(getInertiaMock().patch).toHaveBeenCalled();
        expect(visit?.data).toMatchObject({
            name: 'Paula P',
            email: 'paula@example.test',
            address_town: 'Leeds',
            mobile_number: '+447700900123',
            reason: 'Member phoned in',
        });
    });

    it('locks name and email for SSO accounts', () => {
        render(<UsersEdit user={{ ...editable, sso_managed: true }} />);

        expect(screen.getByLabelText('Name')).toBeDisabled();
        expect(screen.getByLabelText('Email')).toBeDisabled();
        expect(screen.getByText('Name and email come from Cloudron SSO and update when the user signs in.')).toBeInTheDocument();
    });

    it('shows a pending email change', () => {
        render(<UsersEdit user={{ ...editable, pending_email: { email: 'new@example.test', expires_at: null } }} />);

        expect(screen.getByRole('status')).toHaveTextContent('new@example.test');
    });
});
