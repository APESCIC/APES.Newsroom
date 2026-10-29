import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SenderAddresses, { addressTestBlocker } from '../Components/Admin/EmailDomains/SenderAddresses';
import TestSendPanel, { isTestResult, newerResult, refusalMessage } from '../Components/Admin/EmailDomains/TestSendPanel';
import type { SenderAddress, TestResult } from '../Components/Admin/EmailDomains/types';
import { SenderSelect } from '../Pages/Staff/Newsletters/Edit';
import { getInertiaMock } from '../test/inertia';

const result = (overrides: Partial<TestResult> = {}): TestResult => ({
    ok: true,
    from: 'news@apespetcare.org.uk',
    recipients: ['admin@apes.org.uk'],
    mailgun_domain: 'apespetcare.org.uk',
    message_id: 'abc@mailgun.test',
    error: null,
    warning: null,
    status: 'verified',
    checks: { mailgun_state: 'active', spf: true, dkim: true, dmarc: true },
    check_message: 'Verified.',
    tested_at: '2026-10-29T10:00:00+00:00',
    ...overrides,
});

const address: SenderAddress = { id: 4, address: 'news@apespetcare.org.uk', purpose: 'news', label: null, enabled: true, last_test: null };

function respond(status: number, body: unknown) {
    return vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

afterEach(() => vi.restoreAllMocks());

describe('test send helpers', () => {
    it('prefers the newer of the local and stored results', () => {
        const older = result({ tested_at: '2026-10-29T09:00:00+00:00' });
        const newer = result({ tested_at: '2026-10-29T11:00:00+00:00', ok: false });
        expect(newerResult(older, newer)).toBe(newer);
        expect(newerResult(newer, older)).toBe(newer);
        expect(newerResult(null, older)).toBe(older);
    });

    it('explains expired sessions and identity checks', () => {
        expect(refusalMessage(419, { message: 'CSRF token mismatch.' })).toBe('Your session has expired. Reload the page and try again.');
        expect(refusalMessage(423, {})).toContain('Confirm your identity');
        expect(refusalMessage(422, { errors: { extra_recipient: ['Enter a valid email address for the extra recipient.'] } })).toBe(
            'Enter a valid email address for the extra recipient.',
        );
        expect(refusalMessage(429, { message: 'Please wait a minute.' })).toBe('Please wait a minute.');
    });

    it('checks the response shape', () => {
        expect(isTestResult(result())).toBe(true);
        expect(isTestResult({})).toBe(false);
        expect(isTestResult(null)).toBe(false);
    });

    it('blocks tests on disabled domains, disabled addresses and without Mailgun', () => {
        expect(addressTestBlocker(address, true, true)).toContain('Enable the domain');
        expect(addressTestBlocker({ ...address, enabled: false }, false, true)).toContain('Enable this address');
        expect(addressTestBlocker(address, false, false)).toContain('Mailgun API key');
        expect(addressTestBlocker(address, false, true)).toBeNull();
    });
});

describe('TestSendPanel', () => {
    it('shows the new result in a live region, reloads the page data and returns focus on close', async () => {
        const user = userEvent.setup();
        respond(200, result({ tested_at: '2026-10-29T12:00:00+00:00' }));
        render(<TestSendPanel url="/test" subject="news@apespetcare.org.uk" recipient="admin@apes.org.uk" lastTest={result({ ok: false, error: 'old' })} />);

        const trigger = screen.getByRole('button', { name: 'Send test email' });
        await user.click(trigger);
        const input = screen.getByLabelText('Also send to (optional)');
        expect(input).toHaveFocus();

        await user.click(screen.getByRole('button', { name: 'Send test email' }));
        expect(await screen.findByText(/Sent from news@apespetcare.org.uk/)).toBeInTheDocument();
        expect(screen.getByText(/Sent from/).closest('[aria-live="polite"]')).not.toBeNull();
        expect(getInertiaMock().reload).toHaveBeenCalledWith({ only: ['domains', 'alerts'] });

        await user.click(screen.getByRole('button', { name: 'Close' }));
        expect(screen.getByRole('button', { name: 'Send test email' })).toHaveFocus();
    });

    it('shows the throttle message', async () => {
        const user = userEvent.setup();
        respond(429, { message: 'This domain has reached its limit of 20 test emails in 24 hours.' });
        render(<TestSendPanel url="/test" subject="apespetcare.org.uk" recipient="admin@apes.org.uk" lastTest={null} initiallyOpen />);

        await user.click(screen.getByRole('button', { name: 'Send test email' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('limit of 20 test emails');
        expect(getInertiaMock().reload).not.toHaveBeenCalled();
    });

    it('opens when the page offers a test after the panel has mounted', () => {
        const { rerender } = render(<TestSendPanel url="/test" subject="a@b.uk" recipient="admin@apes.org.uk" lastTest={null} />);
        expect(screen.queryByLabelText('Also send to (optional)')).toBeNull();

        act(() => rerender(<TestSendPanel url="/test" subject="a@b.uk" recipient="admin@apes.org.uk" lastTest={null} initiallyOpen />));
        expect(screen.getByLabelText('Also send to (optional)')).toHaveFocus();
    });

    it('shows the reason instead of the button when tests are blocked', () => {
        render(<TestSendPanel url="/test" subject="a@b.uk" recipient="admin@apes.org.uk" lastTest={null} disabledReason="Enable this address to send a test email from it." />);
        expect(screen.queryByRole('button', { name: 'Send test email' })).toBeNull();
        expect(screen.getByText('Enable this address to send a test email from it.')).toBeInTheDocument();
    });
});

describe('SenderAddresses', () => {
    it('returns focus to Edit after cancelling', async () => {
        const user = userEvent.setup();
        render(<SenderAddresses domainId={1} domain="apespetcare.org.uk" addresses={[address]} purposes={[{ value: 'news', label: 'Newsletters' }]} testRecipient="admin@apes.org.uk" offerTestAddressId={null} />);

        await user.click(screen.getByRole('button', { name: 'Edit news@apespetcare.org.uk' }));
        expect(screen.getByLabelText('Address', { selector: '#sender-address-4-address' })).toHaveFocus();
        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByRole('button', { name: 'Edit news@apespetcare.org.uk' })).toHaveFocus();
    });
});

describe('SenderSelect', () => {
    const options = [
        { address: 'news@apesnews.org.uk', label: null, domain: 'apesnews.org.uk', can_send: true },
        { address: 'news@apesshelter.org.uk', label: null, domain: 'apesshelter.org.uk', can_send: false },
    ];

    it('keeps an unlisted saved sender selectable after choosing another', () => {
        render(<SenderSelect value="news@apesnews.org.uk" original="Old@APESNews.org.uk" options={options} reservedLocalParts={[]} onChange={() => {}} />);

        const saved = screen.getByRole('option', { name: 'old@apesnews.org.uk (current, no longer on the allowed list)' });
        expect(saved).not.toBeDisabled();
    });

    it('keeps a saved sender on an unverified domain selectable but not other unverified ones', () => {
        const { rerender } = render(<SenderSelect value="news@apesnews.org.uk" original="news@apesshelter.org.uk" options={options} reservedLocalParts={[]} onChange={() => {}} />);
        expect(screen.getByRole('option', { name: /news@apesshelter.org.uk/ })).not.toBeDisabled();

        rerender(<SenderSelect value="news@apesnews.org.uk" original="news@apesnews.org.uk" options={options} reservedLocalParts={[]} onChange={() => {}} />);
        expect(screen.getByRole('option', { name: /news@apesshelter.org.uk/ })).toBeDisabled();
    });
});
