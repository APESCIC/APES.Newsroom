import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import ContactDetailsFields, { contactFormDefaults, type ContactDetailsValues } from '../../../Components/Account/ContactDetailsFields';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import type { EditableUser } from './types';

type EditForm = ContactDetailsValues & { name: string; email: string; reason: string };

export default function Edit({ user }: { user: EditableUser }) {
    const { data, setData, patch, processing, errors } = useForm<EditForm>({
        ...contactFormDefaults(user.contact_details),
        name: user.name,
        email: user.email,
        reason: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        patch(`/admin/users/${user.id}`);
    }

    return (
        <WorkspaceLayout
            area="Admin"
            active="users"
            title={`Edit ${user.name}`}
            subtitle={user.email}
            actions={
                <Link href={`/admin/users/${user.id}`} className="button-secondary">
                    Back to user
                </Link>
            }
        >
            <Head title={`Edit ${user.name} · Users`} />
            <form className="mx-auto flex max-w-3xl flex-col gap-6" onSubmit={submit}>
                <section className="glass-form-panel flex flex-col gap-4" aria-label="Account">
                    <h2 className="text-lg font-bold text-body">Account</h2>
                    {user.sso_managed && (
                        <p className="text-sm text-muted">Name and email come from Cloudron SSO and update when the user signs in.</p>
                    )}
                    <div>
                        <label htmlFor="name" className="text-sm font-bold text-body">Name</label>
                        <input
                            id="name"
                            className="form-input mt-1"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            disabled={user.sso_managed}
                            required
                        />
                        {errors.name && <p className="text-sm text-danger">{errors.name}</p>}
                    </div>
                    <div>
                        <label htmlFor="email" className="text-sm font-bold text-body">Email</label>
                        {!user.sso_managed && (
                            <p className="text-sm text-muted">A new address only applies once the user confirms it from that inbox.</p>
                        )}
                        <input
                            id="email"
                            type="email"
                            className="form-input mt-1"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled={user.sso_managed}
                            required
                        />
                        {errors.email && <p className="text-sm text-danger">{errors.email}</p>}
                        {user.pending_email && (
                            <p className="mt-1 text-sm text-body" role="status">
                                Waiting for confirmation of <strong>{user.pending_email.email}</strong>.
                            </p>
                        )}
                    </div>
                </section>

                <section className="glass-form-panel flex flex-col gap-4" aria-label="Contact details">
                    <h2 className="text-lg font-bold text-body">Contact details</h2>
                    <p className="text-sm text-muted">Private. Only the account holder and administrators can see these.</p>
                    <ContactDetailsFields
                        values={data}
                        errors={errors}
                        onChange={(field, value) => setData((current) => ({ ...current, [field]: value }))}
                    />
                </section>

                <section className="glass-form-panel flex flex-col gap-3" aria-label="Reason">
                    <label htmlFor="reason" className="text-sm font-bold text-body">
                        Reason (recorded in the audit log)
                    </label>
                    <textarea
                        id="reason"
                        className="form-input"
                        rows={3}
                        maxLength={500}
                        required
                        value={data.reason}
                        onChange={(e) => setData('reason', e.target.value)}
                    />
                    {errors.reason && <p className="text-sm text-danger">{errors.reason}</p>}
                    <button type="submit" disabled={processing} className="button-primary w-fit">
                        Save changes
                    </button>
                </section>
            </form>
        </WorkspaceLayout>
    );
}
