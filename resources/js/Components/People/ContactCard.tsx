import { useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { formatWhen } from '../../Pages/Admin/Users/types';
import type { ContactPerson } from '../../Pages/Staff/People/types';
import ConfirmDialog from '../Workspace/ui/ConfirmDialog';
import FormField from '../Workspace/ui/FormField';
import GlassCard from '../Workspace/ui/GlassCard';
import PillButton from '../Workspace/ui/PillButton';

function DeleteContact({ contact }: { contact: ContactPerson['contact'] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '', confirm_email: '', suppress: true });

    function close() {
        setOpen(false);
        form.reset();
        form.clearErrors();
    }

    return (
        <>
            <PillButton variant="danger" size="sm" icon={Trash2} onClick={() => setOpen(true)}>
                Delete subscriber
            </PillButton>
            <ConfirmDialog
                open={open}
                title="Delete this subscriber?"
                description="Their subscriptions are deleted. The consent history is kept as evidence."
                confirmLabel="Delete subscriber"
                destructive
                processing={form.processing}
                onConfirm={() => form.delete(`/staff/people/contacts/${contact.id}`, { onSuccess: close })}
                onCancel={close}
            >
                <div className="mt-4 flex flex-col gap-3">
                    <FormField label={`Type ${contact.email} to confirm`} error={form.errors.confirm_email}>
                        <input className="form-input" value={form.data.confirm_email} onChange={(e) => form.setData('confirm_email', e.target.value)} autoComplete="off" />
                    </FormField>
                    <label className="flex items-center gap-2 text-sm text-body">
                        <input type="checkbox" checked={form.data.suppress} onChange={(e) => form.setData('suppress', e.target.checked)} />
                        Also suppress the address so it cannot be re-added by an import
                    </label>
                    <FormField label="Reason (recorded in the audit log)" error={form.errors.reason}>
                        <textarea className="form-input" rows={3} maxLength={500} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </FormField>
                </div>
            </ConfirmDialog>
        </>
    );
}

/** Name and email of a subscriber without an account (#569). */
export default function ContactCard({ person }: { person: ContactPerson }) {
    const { contact, can } = person;
    const form = useForm({ name: contact.name ?? '', email: contact.email, reason: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(`/staff/people/contacts/${contact.id}`, { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
    }

    return (
        <GlassCard
            as="section"
            aria-label="Subscriber"
            title="Subscriber"
            description={`Mailing-list contact without a Newsroom account. Added ${formatWhen(contact.created_at)}.`}
            actions={can.delete && <DeleteContact contact={contact} />}
        >
            {can.update ? (
                <form className="flex flex-col gap-4" onSubmit={submit} aria-label="Edit subscriber">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField label="Name" error={form.errors.name}>
                            <input className="form-input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </FormField>
                        <FormField label="Email" hint="Changing it asks them to confirm their subscriptions again from the new address." error={form.errors.email}>
                            <input type="email" className="form-input" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required />
                        </FormField>
                    </div>
                    <FormField label="Reason (recorded in the audit log)" error={form.errors.reason}>
                        <textarea className="form-input" rows={2} maxLength={500} required value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </FormField>
                    <button type="submit" disabled={form.processing} className="sg-btn sg-btn-primary w-fit">
                        Save subscriber
                    </button>
                </form>
            ) : (
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt className="text-muted">Name</dt>
                        <dd className="text-body">{contact.name ?? 'Not given'}</dd>
                    </div>
                    <div>
                        <dt className="text-muted">Email</dt>
                        <dd className="text-body">{contact.email}</dd>
                    </div>
                </dl>
            )}
        </GlassCard>
    );
}
