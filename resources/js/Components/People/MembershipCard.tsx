import { useForm } from '@inertiajs/react';
import { Ban, Gift } from 'lucide-react';
import type { FormEvent } from 'react';
import { formatWhen } from '../../Pages/Admin/Users/types';
import type { UserPerson } from '../../Pages/Staff/People/types';
import ReasonActionButton from '../Staff/ReasonActionButton';
import Badge from '../Workspace/ui/Badge';
import FormField from '../Workspace/ui/FormField';
import GlassCard from '../Workspace/ui/GlassCard';

function StaffNote({ userId, note }: { userId: number; note: NonNullable<UserPerson['membership']['note']> }) {
    const form = useForm({ note: note.text ?? '', reason: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(`/staff/people/users/${userId}/note`, { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
    }

    return (
        <form className="mt-5 flex flex-col gap-3 border-t border-border pt-5" onSubmit={submit} aria-label="Internal member note">
            <FormField
                label="Internal note"
                hint={note.updated_at ? `Last changed ${formatWhen(note.updated_at)}${note.updated_by ? ` by ${note.updated_by}` : ''}. Admins only; never shown to the member.` : 'Admins only; never shown to the member.'}
                error={form.errors.note}
            >
                <textarea className="form-input" rows={4} maxLength={5000} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
            </FormField>
            <FormField label="Reason (recorded in the audit log)" error={form.errors.reason}>
                <input className="form-input" maxLength={500} required value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
            </FormField>
            <button type="submit" disabled={form.processing} className="sg-btn sg-btn-secondary w-fit">
                Save note
            </button>
        </form>
    );
}

/** Stripe state (read-only), complimentary access and the internal note (#570). */
export default function MembershipCard({ person }: { person: UserPerson }) {
    const { membership, can, user } = person;

    return (
        <GlassCard
            as="section"
            aria-label="Membership"
            title="Membership"
            description="Billing comes from Stripe and is read-only here."
            actions={<Badge tone={membership.has_paid_access ? 'ok' : 'neutral'}>{membership.access_label}</Badge>}
        >
            <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt className="text-muted">Stripe status</dt>
                    <dd className="text-body">{membership.status_label}</dd>
                </div>
                <div>
                    <dt className="text-muted">Plan</dt>
                    <dd className="text-body">{membership.plan_name ?? 'None'}</dd>
                </div>
                <div>
                    <dt className="text-muted">Interval</dt>
                    <dd className="text-body">{membership.interval ?? '—'}</dd>
                </div>
                <div>
                    <dt className="text-muted">Current period ends</dt>
                    <dd className="text-body">{formatWhen(membership.current_period_end)}</dd>
                </div>
            </dl>

            <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-5">
                <p className="text-sm text-body">
                    {membership.comp ? (
                        <>
                            Complimentary access since {formatWhen(membership.comp.comped_at)}
                            {membership.comp.by ? ` (granted by ${membership.comp.by})` : ''}
                            {membership.comp.reason ? `: ${membership.comp.reason}` : ''}
                        </>
                    ) : (
                        'No complimentary access.'
                    )}
                </p>
                {can.manage_comp &&
                    (membership.comp ? (
                        <ReasonActionButton
                            label="Remove complimentary access"
                            icon={Ban}
                            title="Remove complimentary access?"
                            description="They lose paid content unless they also have an active Stripe subscription."
                            confirmLabel="Remove access"
                            destructive
                            method="delete"
                            url={`/staff/people/users/${user.id}/comp`}
                        />
                    ) : (
                        <ReasonActionButton
                            label="Grant complimentary access"
                            icon={Gift}
                            title="Grant complimentary access?"
                            description="They can read paid content without paying. Stripe billing is not changed."
                            confirmLabel="Grant access"
                            url={`/staff/people/users/${user.id}/comp`}
                        />
                    ))}
            </div>

            {can.edit_note && membership.note && <StaffNote userId={user.id} note={membership.note} />}
        </GlassCard>
    );
}
