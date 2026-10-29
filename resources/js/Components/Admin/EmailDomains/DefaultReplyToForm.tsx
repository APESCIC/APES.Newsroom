import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

export default function DefaultReplyToForm({ value }: { value: string | null }) {
    const form = useForm({ default_reply_to: value ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/admin/settings/email-domains/default-reply-to', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby="default-reply-to-heading">
            <h2 id="default-reply-to-heading" className="text-base font-semibold text-body">
                Default reply-to
            </h2>
            <p className="text-sm text-muted">
                Used when a newsletter has no reply-to of its own, and on test emails. Use a monitored mailbox. Its domain must be on this list and enabled.
            </p>
            <label htmlFor="default-reply-to" className="text-sm font-semibold text-body">
                Reply-to address
                <input
                    id="default-reply-to"
                    type="email"
                    className="form-input mt-1 w-full"
                    placeholder="newsroom@apes.org.uk"
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.default_reply_to}
                    aria-invalid={form.errors.default_reply_to ? true : undefined}
                    aria-describedby={form.errors.default_reply_to ? 'default-reply-to-error' : undefined}
                    onChange={(event) => form.setData('default_reply_to', event.target.value)}
                />
            </label>
            {form.errors.default_reply_to && (
                <p id="default-reply-to-error" className="text-sm text-danger">
                    {form.errors.default_reply_to}
                </p>
            )}
            <button type="submit" className="sg-btn sg-btn-secondary self-start" disabled={form.processing || !form.isDirty}>
                Save reply-to
            </button>
        </form>
    );
}
