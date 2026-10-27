import { BellOff, BellRing, MailMinus, MailPlus, ShieldOff, ShieldPlus } from 'lucide-react';
import { formatWhen } from '../../Pages/Admin/Users/types';
import { consentLabel, subscriptionLabel, type Mailing, type SubscriptionRow } from '../../Pages/Staff/People/types';
import ReasonActionButton from '../Staff/ReasonActionButton';
import Badge, { type BadgeTone } from '../Workspace/ui/Badge';
import GlassCard from '../Workspace/ui/GlassCard';

function tone(status: SubscriptionRow['status']): BadgeTone {
    switch (status) {
        case 'confirmed':
            return 'ok';
        case 'pending':
            return 'warning';
        case 'unsubscribed':
            return 'error';
        default:
            return 'neutral';
    }
}

/** Per-newsletter status with staff unsubscribe, double opt-in request and suppression (#569). */
export default function SubscriptionsCard({ mailing, canManage, canSuppress }: { mailing: Mailing; canManage: boolean; canSuppress: boolean }) {
    const base = `/staff/people/contacts/${mailing.contact_id}`;

    return (
        <GlassCard
            as="section"
            aria-label="Newsletters"
            title="Newsletters"
            description={
                canManage
                    ? 'Staff can unsubscribe someone or send them a confirmation email. A subscription only starts once they confirm it themselves.'
                    : 'Subscription status for this address.'
            }
            actions={
                canSuppress &&
                (mailing.suppressed ? (
                    <ReasonActionButton
                        label="Remove suppression"
                        icon={ShieldOff}
                        title="Remove the suppression?"
                        description="Newsletters can be sent to this address again. Withdrawn subscriptions stay withdrawn."
                        confirmLabel="Remove suppression"
                        method="delete"
                        url={`${base}/suppression`}
                    />
                ) : (
                    <ReasonActionButton
                        label="Suppress address"
                        icon={ShieldPlus}
                        title="Suppress this address?"
                        description="No newsletter will be sent to this address, whatever its subscriptions say."
                        confirmLabel="Suppress"
                        destructive
                        url={`${base}/suppression`}
                    />
                ))
            }
        >
            <p className="mb-3 text-sm">
                {mailing.suppressed ? <Badge tone="error">Suppressed: no newsletters are sent</Badge> : <Badge tone="ok">Not suppressed</Badge>}
            </p>
            <ul className="divide-y divide-border" aria-label="Newsletter subscriptions">
                {mailing.subscriptions.map((row) => (
                    <li key={`${row.newsletter_id ?? 'orphan'}-${row.subscription_id ?? 'none'}`} className="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div className="min-w-0">
                            <p className="font-semibold text-body">{row.label}</p>
                            <p className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                                <Badge tone={tone(row.status)}>{subscriptionLabel(row.status)}</Badge>
                                {row.confirmed_at && row.status === 'confirmed' && <span>since {formatWhen(row.confirmed_at)}</span>}
                                {row.unsubscribed_at && row.status === 'unsubscribed' && <span>on {formatWhen(row.unsubscribed_at)}</span>}
                            </p>
                        </div>
                        {canManage && (
                            <div className="flex flex-wrap gap-2">
                                {row.can_unsubscribe && row.subscription_id !== null && (
                                    <ReasonActionButton
                                        label="Unsubscribe"
                                        ariaLabel={`Unsubscribe from ${row.label}`}
                                        icon={MailMinus}
                                        title={`Unsubscribe from ${row.label}?`}
                                        description="They stop receiving this newsletter straight away."
                                        confirmLabel="Unsubscribe"
                                        destructive
                                        url={`${base}/subscriptions/${row.subscription_id}/unsubscribe`}
                                    />
                                )}
                                {row.can_subscribe && row.newsletter_id !== null && !mailing.suppressed && (
                                    <ReasonActionButton
                                        label="Send confirmation"
                                        ariaLabel={`Send confirmation for ${row.label}`}
                                        icon={MailPlus}
                                        title={`Ask them to subscribe to ${row.label}?`}
                                        description="We email a confirmation link. The subscription stays pending until they confirm it."
                                        confirmLabel="Send confirmation"
                                        url={`${base}/newsletters/${row.newsletter_id}/subscribe`}
                                    />
                                )}
                            </div>
                        )}
                    </li>
                ))}
            </ul>
            {mailing.consent_history.length > 0 && (
                <details className="mt-4 text-sm">
                    <summary className="cursor-pointer font-medium text-teal-deep">Consent history ({mailing.consent_history.length})</summary>
                    <ul className="mt-2 flex flex-col gap-1.5">
                        {mailing.consent_history.map((event) => (
                            <li key={event.id} className="flex flex-wrap justify-between gap-2">
                                <span className="flex items-center gap-2 text-body">
                                    {event.action === 'unsubscribed' || event.action === 'suppressed' ? (
                                        <BellOff aria-hidden="true" className="h-4 w-4 text-muted" />
                                    ) : (
                                        <BellRing aria-hidden="true" className="h-4 w-4 text-muted" />
                                    )}
                                    {consentLabel(event.action)} · {event.source}
                                </span>
                                <span className="text-muted">{formatWhen(event.created_at)}</span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </GlassCard>
    );
}
