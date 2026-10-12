import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Login from '../Pages/Auth/Login';
import TwoFactorChallenge from '../Pages/Auth/TwoFactorChallenge';
import { getInertiaMock, setMockPage } from '../test/inertia';

const webauthn = vi.hoisted(() => ({
    browserSupportsWebAuthn: vi.fn(() => true),
    browserSupportsWebAuthnAutofill: vi.fn(async () => false),
    startAuthentication: vi.fn(),
    cancelCeremony: vi.fn(),
}));

vi.mock('@simplewebauthn/browser', async (importOriginal) => ({
    ...(await importOriginal<Record<string, unknown>>()),
    browserSupportsWebAuthn: webauthn.browserSupportsWebAuthn,
    browserSupportsWebAuthnAutofill: webauthn.browserSupportsWebAuthnAutofill,
    startAuthentication: webauthn.startAuthentication,
    WebAuthnAbortService: { cancelCeremony: webauthn.cancelCeremony },
}));

const options = { challenge: 'abc', rpId: 'localhost' };

beforeEach(() => {
    webauthn.browserSupportsWebAuthn.mockReturnValue(true);
    webauthn.browserSupportsWebAuthnAutofill.mockResolvedValue(false);
});

function stubOptions(status = 200, body: unknown = options) {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status }));
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

describe('passkey sign-in on the login page', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        webauthn.startAuthentication.mockReset();
        webauthn.cancelCeremony.mockReset();
    });

    it('offers passkey autofill on the email field', () => {
        render(<Login staffLoginUrl={null} staffLoginLabel={null} />);

        expect(screen.getByLabelText('Email')).toHaveAttribute('autocomplete', 'username webauthn');
    });

    it('signs in with a passkey and keeps the remember choice', async () => {
        const fetchMock = stubOptions();
        webauthn.startAuthentication.mockResolvedValue({ id: 'cred-1' });
        render(<Login staffLoginUrl={null} staffLoginLabel={null} rememberDays={30} />);

        fireEvent.click(screen.getByLabelText('Keep me signed in on this device for 30 days'));
        fireEvent.click(screen.getByRole('button', { name: 'Sign in with a passkey' }));

        await waitFor(() => expect(getInertiaMock().post).toHaveBeenCalled());

        expect(fetchMock).toHaveBeenCalledWith('/passkeys/login/options', expect.objectContaining({ method: 'POST' }));
        expect(webauthn.startAuthentication).toHaveBeenCalledWith({ optionsJSON: options });
        const [url, data] = getInertiaMock().post.mock.calls[0];
        expect(url).toBe('/passkeys/login');
        expect(data).toEqual({ credential: JSON.stringify({ id: 'cred-1' }), remember: true });
    });

    it('explains a cancelled passkey prompt', async () => {
        stubOptions();
        webauthn.startAuthentication.mockRejectedValue(Object.assign(new Error('no'), { name: 'NotAllowedError' }));
        render(<Login staffLoginUrl={null} staffLoginLabel={null} />);

        fireEvent.click(screen.getByRole('button', { name: 'Sign in with a passkey' }));

        expect(await screen.findByText('Passkey sign-in was cancelled or timed out.')).toBeInTheDocument();
        expect(getInertiaMock().post).not.toHaveBeenCalled();
    });

    it('shows the server refusal', () => {
        const original = getInertiaMock().page.props;
        setMockPage({ ...original, errors: { passkey: 'That passkey could not be used to sign in.' } });

        try {
            render(<Login staffLoginUrl={null} staffLoginLabel={null} />);

            expect(screen.getByText('That passkey could not be used to sign in.')).toBeInTheDocument();
        } finally {
            setMockPage(original);
        }
    });

    it('starts browser autofill when supported and signs in from it', async () => {
        webauthn.browserSupportsWebAuthnAutofill.mockResolvedValue(true);
        stubOptions();
        webauthn.startAuthentication.mockResolvedValue({ id: 'cred-2' });
        render(<Login staffLoginUrl={null} staffLoginLabel={null} />);

        await waitFor(() => expect(getInertiaMock().post).toHaveBeenCalled());

        expect(webauthn.startAuthentication).toHaveBeenCalledWith({ optionsJSON: options, useBrowserAutofill: true });
        expect(getInertiaMock().post.mock.calls[0][1]).toEqual({ credential: JSON.stringify({ id: 'cred-2' }), remember: false });
    });

    it('cancels a pending autofill when leaving the page', () => {
        const { unmount } = render(<Login staffLoginUrl={null} staffLoginLabel={null} />);

        unmount();

        expect(webauthn.cancelCeremony).toHaveBeenCalled();
    });

    it('hides the passkey button when the browser has no support', () => {
        webauthn.browserSupportsWebAuthn.mockReturnValue(false);
        render(<Login staffLoginUrl={null} staffLoginLabel={null} />);

        expect(screen.queryByRole('button', { name: 'Sign in with a passkey' })).not.toBeInTheDocument();
    });
});

describe('passkey on the two-factor challenge', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        webauthn.startAuthentication.mockReset();
    });

    it('uses a passkey as the second factor', async () => {
        const fetchMock = stubOptions();
        webauthn.startAuthentication.mockResolvedValue({ id: 'cred-3' });
        render(<TwoFactorChallenge has_passkeys />);

        fireEvent.click(screen.getByRole('button', { name: 'Use a passkey' }));

        await waitFor(() => expect(getInertiaMock().post).toHaveBeenCalled());

        expect(fetchMock).toHaveBeenCalledWith('/two-factor-challenge/passkey/options', expect.objectContaining({ method: 'POST' }));
        const [url, data] = getInertiaMock().post.mock.calls[0];
        expect(url).toBe('/two-factor-challenge/passkey');
        expect(data).toEqual({ credential: JSON.stringify({ id: 'cred-3' }) });
    });

    it('only offers a passkey to accounts that have one', () => {
        render(<TwoFactorChallenge has_passkeys={false} />);

        expect(screen.queryByRole('button', { name: 'Use a passkey' })).not.toBeInTheDocument();
    });
});
