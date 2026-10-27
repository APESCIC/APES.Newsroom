import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { EMPTY_CONTACT_DETAILS } from '../Components/Account/ContactDetailsFields';
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

function renderProfile(contactDetails = EMPTY_CONTACT_DETAILS, status?: string) {
    render(
        <Profile
            user={user}
            membership={membership}
            contact_details={contactDetails}
            status={status}
            can_delete_account
            deletion_block_reason={null}
        />,
    );

    return within(screen.getByRole('region', { name: 'Contact details' }));
}

describe('account contact details', () => {
    it('saves address, mobile and a WhatsApp number', async () => {
        const actor = userEvent.setup();
        const section = renderProfile();

        await actor.type(section.getByLabelText('Address line 1'), '10 Example Street');
        await actor.type(section.getByLabelText('Postcode'), 'LS1 4AP');
        await actor.type(section.getByLabelText(/^Mobile number/), '07700 900123');
        await actor.type(section.getByLabelText('WhatsApp number'), '07911 123456');
        await actor.click(section.getByRole('button', { name: 'Save contact details' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/account/contact-details');
        expect(visit?.data).toMatchObject({
            address_line1: '10 Example Street',
            address_postcode: 'LS1 4AP',
            address_country: 'GB',
            mobile_number: '07700 900123',
            whatsapp_type: 'number',
            whatsapp_value: '07911 123456',
            whatsapp_same_as_mobile: false,
        });
    });

    it('switches WhatsApp between number and username', async () => {
        const actor = userEvent.setup();
        const section = renderProfile();

        expect(section.getByLabelText('WhatsApp number')).toHaveAttribute('type', 'tel');
        await actor.click(section.getByRole('radio', { name: 'Username' }));
        expect(section.getByLabelText('WhatsApp username')).toHaveAttribute('type', 'text');
        expect(section.queryByLabelText('WhatsApp number')).not.toBeInTheDocument();
    });

    it('hides the WhatsApp value when it is the same as the mobile', async () => {
        const actor = userEvent.setup();
        const section = renderProfile({ ...EMPTY_CONTACT_DETAILS, mobile_number: '+447700900123' });

        await actor.click(section.getByLabelText('Same as mobile number'));

        expect(section.queryByLabelText('WhatsApp number')).not.toBeInTheDocument();
        expect(section.queryByRole('radiogroup')).not.toBeInTheDocument();
        await actor.click(section.getByRole('button', { name: 'Save contact details' }));
        expect(getInertiaMock().visits.at(-1)?.data).toMatchObject({ whatsapp_same_as_mobile: true });
    });

    it('shows server errors inline next to the field', async () => {
        const actor = userEvent.setup();
        const section = renderProfile();

        await actor.click(section.getByRole('button', { name: 'Save contact details' }));
        getInertiaMock().visits.at(-1)?.options.onError?.({ mobile_number: 'Enter a valid phone number.' });

        const input = await section.findByLabelText(/^Mobile number/);
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(section.getByText('Enter a valid phone number.')).toBeInTheDocument();
    });

    it('confirms when details were saved', () => {
        const section = renderProfile(EMPTY_CONTACT_DETAILS, 'contact-details-updated');

        expect(section.getByRole('status')).toHaveTextContent('Contact details saved.');
    });
});
