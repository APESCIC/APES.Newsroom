import { useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { formatWhen, methodLabel, roleLabel } from '../../Pages/Admin/Users/types';
import type { UserPerson } from '../../Pages/Staff/People/types';
import ContactDetailsFields, { contactFormDefaults, type ContactDetailsValues } from '../Account/ContactDetailsFields';
import ContactDetailsSummary from '../Account/ContactDetailsSummary';
import Badge from '../Workspace/ui/Badge';
import FormField from '../Workspace/ui/FormField';
import GlassCard from '../Workspace/ui/GlassCard';
import PillButton from '../Workspace/ui/PillButton';

type DetailsForm = ContactDetailsValues & { name: string; email: string; reason: string };

function Facts({ person }: { person: UserPerson }) {
    const { user } = person;

    return (
        <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt className="text-muted">Role</dt>
                <dd className="text-body">{roleLabel(user.role)}</dd>
            </div>
            <div>
                <dt className="text-muted">Sign-in method</dt>
                <dd className="text-body">{methodLabel(user.sign_in_method)}</dd>
            </div>
            <div>
                <dt className="text-muted">Email</dt>
                <dd className="text-body">{user.email_verified ? 'Verified' : 'Not verified'}</dd>
            </div>
            <div>
                <dt className="text-muted">Joined</dt>
                <dd className="text-body">{formatWhen(user.created_at)}</dd>
            </div>
        </dl>
    );
}

/** Account facts and, when allowed, the name, email and contact details form (#568, #573). */
export default function AccountCard({ person }: { person: UserPerson }) {
    const { user } = person;
    const [editing, setEditing] = useState(false);
    const form = useForm<DetailsForm>({
        ...contactFormDefaults(person.contact_details),
        name: user.name,
        email: user.email,
        reason: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(`/staff/people/users/${user.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('reason', '');
                setEditing(false);
            },
        });
    }

    return (
        <GlassCard
            as="section"
            aria-label="Account"
            title="Account"
            actions={
                <>
                    {user.locked ? <Badge tone="error">Locked</Badge> : <Badge tone="ok">Active</Badge>}
                    {person.can.update && !editing && (
                        <PillButton size="sm" variant="secondary" icon={Pencil} onClick={() => setEditing(true)}>
                            Edit details
                        </PillButton>
                    )}
                </>
            }
        >
            <Facts person={person} />
            {user.pending_email && (
                <p className="mt-3 text-sm text-body" role="status">
                    Waiting for them to confirm a change to <strong>{user.pending_email.email}</strong>
                    {user.pending_email.expires_at ? ` (link expires ${formatWhen(user.pending_email.expires_at)})` : ''}.
                </p>
            )}

            {person.contact_details && !editing && (
                <div className="mt-5 border-t border-border pt-5">
                    <h3 className="mb-3 text-sm font-semibold text-body">Contact details</h3>
                    <ContactDetailsSummary details={person.contact_details} />
                </div>
            )}

            {person.can.update && editing && (
                <form className="mt-5 flex flex-col gap-4 border-t border-border pt-5" onSubmit={submit} aria-label="Edit account details">
                    {user.sso_managed && <p className="text-sm text-muted">Name and email come from Cloudron SSO and update when they sign in.</p>}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label="Name" error={form.errors.name}>
                            <input className="form-input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} disabled={user.sso_managed} required />
                        </FormField>
                        <FormField
                            label="Email"
                            hint={user.sso_managed ? undefined : 'A new address only applies once they confirm it from that inbox.'}
                            error={form.errors.email}
                        >
                            <input
                                type="email"
                                className="form-input"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value)}
                                disabled={user.sso_managed}
                                required
                            />
                        </FormField>
                    </div>
                    <h3 className="text-sm font-semibold text-body">Contact details</h3>
                    <p className="-mt-3 text-xs text-muted">Private. Only the account holder and staff can see these.</p>
                    <ContactDetailsFields
                        values={form.data}
                        errors={form.errors}
                        onChange={(field, value) => form.setData((current) => ({ ...current, [field]: value }))}
                    />
                    <FormField label="Reason (recorded in the audit log)" error={form.errors.reason}>
                        <textarea className="form-input" rows={2} maxLength={500} required value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </FormField>
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" disabled={form.processing} className="sg-btn sg-btn-primary">
                            Save account details
                        </button>
                        <PillButton
                            variant="ghost"
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                setEditing(false);
                            }}
                        >
                            Cancel
                        </PillButton>
                    </div>
                </form>
            )}
        </GlassCard>
    );
}
