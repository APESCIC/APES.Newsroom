import { router } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';
import Badge from '../../Workspace/ui/Badge';
import type { TestResult } from './types';

function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

function CheckBadge({ label, pass }: { label: string; pass: boolean }) {
    return <Badge tone={pass ? 'ok' : 'error'}>{`${label} ${pass ? 'pass' : 'fail'}`}</Badge>;
}

export function TestResultSummary({ result }: { result: TestResult }) {
    return (
        <div className="flex flex-col gap-2 text-sm" role="status">
            <p className={result.ok ? 'font-semibold text-success' : 'font-semibold text-danger'}>
                {result.ok
                    ? `Sent from ${result.from} to ${result.recipients.join(', ')}.${result.message_id ? ` Mailgun message ID: ${result.message_id}` : ''}`
                    : `Not sent from ${result.from}: ${result.error ?? 'unknown error'}`}
            </p>
            {result.warning && <p className="text-warning">{result.warning}</p>}
            <div className="flex flex-wrap items-center gap-2">
                <Badge tone={result.checks.mailgun_state === 'active' ? 'ok' : 'error'}>
                    {result.checks.mailgun_state === 'active' ? 'Active' : `Mailgun: ${result.checks.mailgun_state ?? 'not checked'}`}
                </Badge>
                <CheckBadge label="SPF" pass={result.checks.spf} />
                <CheckBadge label="DKIM" pass={result.checks.dkim} />
                <CheckBadge label="DMARC" pass={result.checks.dmarc} />
            </div>
            <p className="text-xs text-muted">
                {result.check_message} Tested {formatTime(result.tested_at)} through {result.mailgun_domain}.
            </p>
        </div>
    );
}

/**
 * Send test email: posts to the JSON test endpoint and shows the outcome
 * inline. The signed-in admin always receives it; one extra address is optional.
 */
export default function TestSendPanel({
    url,
    subject,
    recipient,
    lastTest,
    initiallyOpen = false,
}: {
    url: string;
    subject: string;
    recipient: string;
    lastTest: TestResult | null;
    initiallyOpen?: boolean;
}) {
    const [open, setOpen] = useState(initiallyOpen);
    const [extra, setExtra] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<TestResult | null>(null);
    const extraId = useId();
    const shown = result ?? lastTest;

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ extra_recipient: extra.trim() === '' ? null : extra.trim() }),
            });
            const payload = await response.json().catch(() => ({}));
            if (response.status === 423) {
                router.visit('/account/confirm-identity');
                return;
            }
            if (!response.ok) {
                const fieldError = payload?.errors?.extra_recipient?.[0];
                setError(fieldError ?? payload?.message ?? 'The test email could not be sent. Please try again.');
                return;
            }
            setResult(payload as TestResult);
        } catch {
            setError('The test email could not be sent. Check your connection and try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            {!open ? (
                <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm self-start" onClick={() => setOpen(true)}>
                    Send test email
                </button>
            ) : (
                <form onSubmit={submit} className="flex flex-col gap-2 rounded-xl border border-border bg-white/50 p-3" aria-label={`Send a test email from ${subject}`}>
                    <p className="text-sm text-body">
                        Sends a test from <span className="font-mono">{subject}</span> to you ({recipient}) straight away.
                    </p>
                    <label htmlFor={extraId} className="text-sm font-semibold text-body">
                        Also send to (optional)
                        <input
                            id={extraId}
                            type="email"
                            className="form-input mt-1 w-full"
                            autoComplete="off"
                            value={extra}
                            onChange={(event) => setExtra(event.target.value)}
                        />
                    </label>
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className="sg-btn sg-btn-primary sg-btn-sm" disabled={busy}>
                            {busy ? 'Sending…' : 'Send test email'}
                        </button>
                        <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={busy} onClick={() => setOpen(false)}>
                            Close
                        </button>
                    </div>
                    {error && (
                        <p role="alert" className="text-sm text-danger">
                            {error}
                        </p>
                    )}
                </form>
            )}
            {shown && <TestResultSummary result={shown} />}
        </div>
    );
}
