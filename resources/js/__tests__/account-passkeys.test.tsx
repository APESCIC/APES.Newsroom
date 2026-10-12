import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { AccountPasskey } from '../Components/Account/PasskeysSection';
import Security from '../Pages/Account/Security';
import { getInertiaMock } from '../test/inertia';

const webauthn = vi.hoisted(() => ({
    browserSupportsWebAuthn: vi.fn(() => true),
    startRegistration: vi.fn(),
}));

vi.mock('@simplewebauthn/browser', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    browserSupportsWebAuthn: webauthn.browserSupportsWebAuthn,
    startRegistration: webauthn.startRegistration,
}));

const passkeys: AccountPasskey[] = [
    { id: 4, name: 'Phone', created_at: '2026-10-01T09:00:00+00:00', last_used_at: '2026-10-11T18:30:00+00:00' },
    { id: 7, name: 'Security key', created_at: '2026-10-05T09:00:00+00:00', last_used_at: null },
];

function renderSecurity(list: AccountPasskey[] | null = passkeys) {
    return render(<Security sso_managed={false} has_password passkeys={list} />);
}

describe('passkeys on account security', () => {
    beforeEach(() => {
        webauthn.browserSupportsWebAuthn.mockReturnValue(true);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
        webauthn.startRegistration.mockReset();
    });

    it('lists passkeys with when they were added and last used', () => {
        renderSecurity();

        const list = screen.getByRole('list', { name: 'Your passkeys' });
        expect(list).toHaveTextContent('Phone');
        expect(list).toHaveTextContent('Added 1 Oct 2026');
        expect(list).toHaveTextContent('Last used 11 Oct 2026');
        expect(list).toHaveTextContent('Not used yet');
    });

    it('is hidden for SSO-managed accounts', () => {
        render(<Security sso_managed has_password={false} passkeys={null} />);

        expect(screen.queryByRole('heading', { name: 'Passkeys' })).not.toBeInTheDocument();
    });

    it('explains when the browser has no passkey support', () => {
        webauthn.browserSupportsWebAuthn.mockReturnValue(false);
        renderSecurity([]);

        expect(screen.getByText('This browser does not support passkeys.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Add a passkey' })).not.toBeInTheDocument();
    });

    it('creates a passkey and sends it with its name', async () => {
        const options = { challenge: 'abc', rp: { id: 'localhost', name: 'APES Newsroom' } };
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(options), { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);
        webauthn.startRegistration.mockResolvedValue({ id: 'cred-1', type: 'public-key' });
        renderSecurity([]);

        fireEvent.change(screen.getByLabelText('Name for this passkey'), { target: { value: ' Work laptop ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add a passkey' }));

        await waitFor(() => expect(getInertiaMock().post).toHaveBeenCalled());

        expect(fetchMock).toHaveBeenCalledWith('/account/passkeys/options', expect.objectContaining({ method: 'POST' }));
        expect(webauthn.startRegistration).toHaveBeenCalledWith({ optionsJSON: options });
        const [url, data] = getInertiaMock().post.mock.calls[0];
        expect(url).toBe('/account/passkeys');
        expect(data).toEqual({ name: 'Work laptop', credential: JSON.stringify({ id: 'cred-1', type: 'public-key' }) });
    });

    it('sends the user to confirm their identity first when needed', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{}', { status: 423 })));
        renderSecurity([]);

        fireEvent.change(screen.getByLabelText('Name for this passkey'), { target: { value: 'Phone' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add a passkey' }));

        await waitFor(() => expect(getInertiaMock().visit).toHaveBeenCalledWith('/account/confirm-identity'));
        expect(webauthn.startRegistration).not.toHaveBeenCalled();
    });

    it('explains when the user cancels on their device', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{"challenge":"abc"}', { status: 200 })));
        webauthn.startRegistration.mockRejectedValue(Object.assign(new Error('cancelled'), { name: 'NotAllowedError' }));
        renderSecurity([]);

        fireEvent.change(screen.getByLabelText('Name for this passkey'), { target: { value: 'Phone' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add a passkey' }));

        expect(await screen.findByText('Passkey setup was cancelled or timed out.')).toBeInTheDocument();
        expect(getInertiaMock().post).not.toHaveBeenCalled();
    });

    it('shows the server message when the limit is reached', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{"message":"You have reached the limit of 20 passkeys."}', { status: 422 })));
        renderSecurity([]);

        fireEvent.change(screen.getByLabelText('Name for this passkey'), { target: { value: 'Phone' } });
        fireEvent.click(screen.getByRole('button', { name: 'Add a passkey' }));

        expect(await screen.findByText('You have reached the limit of 20 passkeys.')).toBeInTheDocument();
    });

    it('renames a passkey', () => {
        renderSecurity();

        fireEvent.click(screen.getByRole('button', { name: 'Rename Phone' }));
        fireEvent.change(screen.getByLabelText('New name'), { target: { value: 'Old phone' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        const visit = getInertiaMock().visits.at(-1);
        expect(visit?.url).toBe('/account/passkeys/4');
        expect(visit?.data).toEqual({ name: 'Old phone' });
    });

    it('removes a passkey after confirmation', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        renderSecurity();

        fireEvent.click(screen.getByRole('button', { name: 'Remove Security key' }));

        expect(getInertiaMock().delete).toHaveBeenCalledWith('/account/passkeys/7', { preserveScroll: true });
    });
});
