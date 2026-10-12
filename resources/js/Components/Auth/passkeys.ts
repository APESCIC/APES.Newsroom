import { WebAuthnError } from '@simplewebauthn/browser';

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

/**
 * Ask the server for a fresh, single-use WebAuthn challenge.
 */
export function requestPasskeyOptions(url: string): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
    });
}

function wasCancelled(error: unknown): boolean {
    return error instanceof Error && (error.name === 'NotAllowedError' || error.name === 'AbortError');
}

export function registrationErrorMessage(error: unknown): string {
    if (error instanceof WebAuthnError && error.code === 'ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED') {
        return 'This device already has a passkey for your account.';
    }

    if (wasCancelled(error)) {
        return 'Passkey setup was cancelled or timed out.';
    }

    return 'Your browser could not create a passkey. Please try again.';
}

export function signInErrorMessage(error: unknown): string {
    if (wasCancelled(error)) {
        return 'Passkey sign-in was cancelled or timed out.';
    }

    return 'Your browser could not use a passkey. Please try again.';
}
