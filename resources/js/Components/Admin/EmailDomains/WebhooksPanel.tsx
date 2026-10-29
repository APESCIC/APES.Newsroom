import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import Badge from '../../Workspace/ui/Badge';

export type WebhookStatus = {
    events: Record<string, { registered: boolean; urls: string[] }>;
    tracking: { open: boolean | null; click: boolean | null };
    error: string | null;
    checked_at: string;
};

export type WebhookManagement = {
    configured: boolean;
    signing_key_configured: boolean;
    uses_sending_key: boolean;
    rotate_due: boolean;
    can_manage: boolean;
    callback_url: string;
};

const EVENT_LABELS: Record<string, string> = {
    delivered: 'Delivered',
    permanent_fail: 'Bounced',
    temporary_fail: 'Temporary failure',
    complained: 'Spam complaint',
    unsubscribed: 'Unsubscribed',
    opened: 'Opened',
    clicked: 'Clicked',
};

function trackingLabel(value: boolean | null): string {
    if (value === null) return 'unknown';
    return value ? 'on' : 'off';
}

/** Mailgun webhooks and open/click tracking for one sending domain (#621, #622). */
export default function WebhooksPanel({
    domainId,
    domain,
    status,
    trackOpens,
    trackClicks,
    management,
}: {
    domainId: number;
    domain: string;
    status: WebhookStatus | null;
    trackOpens: boolean;
    trackClicks: boolean;
    management: WebhookManagement;
}) {
    const [busy, setBusy] = useState(false);
    const base = `/admin/settings/email-domains/${domainId}`;
    const tracking = useForm({ track_opens: trackOpens, track_clicks: trackClicks });
    const events = Object.entries(status?.events ?? {});
    const missing = events.filter(([, event]) => !event.registered).length;

    const act = (method: 'post' | 'delete', url: string) => {
        setBusy(true);
        const options = { preserveScroll: true, onFinish: () => setBusy(false) };
        if (method === 'delete') router.delete(url, options);
        else router.post(url, {}, options);
    };

    const saveTracking = (event: FormEvent) => {
        event.preventDefault();
        tracking.put(`${base}/tracking`, { preserveScroll: true, onSuccess: () => tracking.setDefaults() });
    };

    return (
        <details className="text-sm">
            <summary className="min-h-11 cursor-pointer py-2 font-semibold text-body">
                Webhooks and tracking{' '}
                {status && !status.error && (
                    <Badge tone={missing === 0 && events.length > 0 ? 'ok' : 'warning'}>{missing === 0 && events.length > 0 ? 'Registered' : `${missing} missing`}</Badge>
                )}
            </summary>
            <div className="flex flex-col gap-3 pt-2">
                <p className="text-muted">
                    Mailgun sends bounces, complaints, unsubscribes, opens and clicks for <span className="font-mono">{domain}</span> to{' '}
                    <span className="font-mono break-all">{management.callback_url}</span>.
                </p>
                {!management.signing_key_configured && (
                    <p className="text-warning">Set the Mailgun webhook signing key under Integrations, or incoming events are refused.</p>
                )}
                {!management.configured && <p className="text-warning">Set the Mailgun webhook management key under Integrations to manage webhooks from here.</p>}
                {management.uses_sending_key && (
                    <p className="text-warning">The webhook management key is the same as the sending key. Use a separate Developer-role key if your plan has one.</p>
                )}
                {management.rotate_due && <p className="text-warning">The webhook management key is over 90 days old. Rotate it in Mailgun and update it under Integrations.</p>}
                {status?.error && (
                    <p role="alert" className="text-danger">
                        {status.error}
                    </p>
                )}
                {events.length > 0 && (
                    <ul className="flex flex-wrap gap-2" aria-label={`Webhooks on ${domain}`}>
                        {events.map(([id, event]) => (
                            <li key={id}>
                                <Badge tone={event.registered ? 'ok' : 'error'}>{`${EVENT_LABELS[id] ?? id}: ${event.registered ? 'registered' : 'missing'}`}</Badge>
                            </li>
                        ))}
                    </ul>
                )}
                {status && (
                    <p className="text-xs text-muted">
                        Mailgun tracking: opens {trackingLabel(status.tracking.open)}, clicks {trackingLabel(status.tracking.click)}. Checked{' '}
                        {new Date(status.checked_at).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' })}.
                    </p>
                )}
                <div className="flex flex-wrap gap-2">
                    <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={busy || !management.configured} onClick={() => act('post', `${base}/webhooks/refresh`)}>
                        Check webhooks
                    </button>
                    {management.can_manage && (
                        <>
                            <button type="button" className="sg-btn sg-btn-primary sg-btn-sm" disabled={busy || !management.configured} onClick={() => act('post', `${base}/webhooks/register`)}>
                                Register
                            </button>
                            <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={busy || !management.configured} onClick={() => act('post', `${base}/webhooks/repair`)}>
                                Repair
                            </button>
                            <button type="button" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={busy || !management.configured} onClick={() => act('delete', `${base}/webhooks`)}>
                                Remove
                            </button>
                        </>
                    )}
                </div>
                {management.can_manage ? (
                    <form onSubmit={saveTracking} className="flex flex-col gap-2 border-t border-border pt-3" aria-label={`Tracking on ${domain}`}>
                        <p className="text-muted">Readers still choose for themselves: opens are only tracked for readers who opted in, clicks unless they opted out.</p>
                        <label className="inline-flex min-h-11 items-center gap-2">
                            <input type="checkbox" checked={tracking.data.track_opens} onChange={(event) => tracking.setData('track_opens', event.target.checked)} />
                            Allow open tracking on this domain
                        </label>
                        <label className="inline-flex min-h-11 items-center gap-2">
                            <input type="checkbox" checked={tracking.data.track_clicks} onChange={(event) => tracking.setData('track_clicks', event.target.checked)} />
                            Allow click tracking on this domain
                        </label>
                        <button type="submit" className="sg-btn sg-btn-secondary sg-btn-sm self-start" disabled={tracking.processing || !tracking.isDirty}>
                            Save tracking
                        </button>
                    </form>
                ) : (
                    <p className="text-xs text-muted">
                        Newsroom settings: open tracking {trackOpens ? 'allowed' : 'off'}, click tracking {trackClicks ? 'allowed' : 'off'}. Only super admins can change
                        webhooks and tracking.
                    </p>
                )}
            </div>
        </details>
    );
}
