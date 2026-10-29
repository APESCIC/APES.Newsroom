import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState, type FormEvent } from 'react';
import Badge from '../../Workspace/ui/Badge';
import type { TestResult } from './types';

export function xsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}

export function isTestResult(value: unknown): value is TestResult {
    if (typeof value !== 'object' || value === null) return false;
    const candidate = value as Partial<TestResult>;
    return (
        typeof candidate.ok === 'boolean' &&
        typeof candidate.from === 'string' &&
        Array.isArray(candidate.recipients) &&
        typeof candidate.tested_at === 'string' &&
        typeof candidate.checks === 'object' &&
        candidate.checks !== null
    );
}

/** The more recent of the result just returned and the one stored on the page. */
export function newerResult(local: TestResult | null, stored: TestResult | null): TestResult | null {
    if (!local) return stored;
    if (!stored) return local;
    return Date.parse(stored.tested_at) > Date.parse(local.tested_at) ? stored : local;
}

/** A message for a refused test request, by HTTP status. */
export function refusalMessage(status: number, payload: unknown): string {
    const body = (typeof payload === 'object' && payload !== null ? payload : {}) as { message?: unknown; errors?: Record<string, unknown> };
    const fieldError = Array.isArray(body.errors?.extra_recipient) ? body.errors.extra_recipient[0] : undefined;
    if (typeof fieldError === 'string') return fieldError;
    if (status === 419) return 'Your session has expired. Reload the page and try again.';
    if (status === 423) return 'Confirm your identity to send test emails. Taking you there now.';
    if (typeof body.message === 'string' && body.message !== '' && status !== 500) return body.message;
    return 'The test email could not be sent. Please try again.';
}

function CheckBadge({ label, pass }: { label: string; pass: boolean }) {
    return <Badge tone={pass ? 'ok' : 'error'}>{`${label} ${pass ? 'pass' : 'fail'}`}</Badge>;
}

export function TestResultSummary({ result }: { result: TestResult }) {
    return (
        <div className="flex flex-col gap-2 text-sm">
            <p className={result.ok ? 'font-semibold text-success' : 'font-semibold text-danger'}>
                {result.ok
                    ? `Sent from ${result.from} to ${result.recipients.join(', ')}.${result.message_id ? ` Message ID: ${result.message_id}` : ''}`
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
    disabledReason = null,
}: {
    url: string;
    subject: string;
    recipient: string;
    lastTest: TestResult | null;
    initiallyOpen?: boolean;
    disabledReason?: string | null;
}) {
    const [open, setOpen] = useState(initiallyOpen && !disabledReason);
    const [extra, setExtra] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<TestResult | null>(null);
    const extraId = useId();
    const containerRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const moveFocus = useRef<'input' | 'trigger' | null>(initiallyOpen ? 'input' : null);
    const shown = newerResult(result, lastTest);

    useEffect(() => {
        if (initiallyOpen && !disabledReason) {
            moveFocus.current = 'input';
            setOpen(true);
        }
    }, [initiallyOpen, disabledReason]);

    useEffect(() => {
        if (moveFocus.current === 'input' && open) {
            containerRef.current?.scrollIntoView?.({ block: 'nearest' });
            inputRef.current?.focus();
        } else if (moveFocus.current === 'trigger' && !open) {
            triggerRef.current?.focus();
        }
        moveFocus.current = null;
    }, [open]);

    const toggle = (next: boolean) => {
        moveFocus.current = next ? 'input' : 'trigger';
        setOpen(next);
    };

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
            const payload: unknown = await response.json().catch(() => null);
            if (!response.ok) {
                setError(refusalMessage(response.status, payload));
                if (response.status === 423) router.visit('/account/confirm-identity');
                return;
            }
            if (!isTestResult(payload)) {
                setError('The test email response could not be read. Reload the page to see the latest result.');
                return;
            }
            setResult(payload);
            router.reload({ only: ['domains', 'alerts'] });
        } catch {
            setError('The test email could not be sent. Check your connection and try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div ref={containerRef} className="flex flex-col gap-2">
            {disabledReason ? (
                <p className="text-xs text-muted">{disabledReason}</p>
            ) : !open ? (
                <button ref={triggerRef} type="button" className="sg-btn sg-btn-secondary sg-btn-sm self-start" onClick={() => toggle(true)}>
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
                            ref={inputRef}
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
                        <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={busy} onClick={() => toggle(false)}>
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
            <div aria-live="polite" aria-atomic="true">
                {shown && <TestResultSummary result={shown} />}
            </div>
        </div>
    );
}
