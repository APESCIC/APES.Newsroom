import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import Badge from '../../Workspace/ui/Badge';
import TestSendPanel from './TestSendPanel';
import type { PurposeOption, SenderAddress, SenderAddressPurpose } from './types';

function purposeLabel(purpose: SenderAddressPurpose, purposes: PurposeOption[]): string {
    return purposes.find((option) => option.value === purpose)?.label ?? purpose;
}

function AddressFields({
    idPrefix,
    form,
    purposes,
    placeholder,
    autoFocus = false,
}: {
    idPrefix: string;
    form: ReturnType<typeof useForm<{ address: string; purpose: SenderAddressPurpose; label: string }>>;
    purposes: PurposeOption[];
    placeholder: string;
    autoFocus?: boolean;
}) {
    const addressRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (autoFocus) addressRef.current?.focus();
    }, [autoFocus]);

    return (
        <div className="grid gap-2 sm:grid-cols-3">
            <label htmlFor={`${idPrefix}-address`} className="text-sm font-semibold text-body">
                Address
                <input
                    ref={addressRef}
                    id={`${idPrefix}-address`}
                    type="email"
                    className="form-input mt-1 w-full"
                    placeholder={placeholder}
                    autoComplete="off"
                    spellCheck={false}
                    value={form.data.address}
                    aria-invalid={form.errors.address ? true : undefined}
                    aria-describedby={form.errors.address ? `${idPrefix}-address-error` : undefined}
                    onChange={(event) => form.setData('address', event.target.value)}
                />
            </label>
            <label htmlFor={`${idPrefix}-purpose`} className="text-sm font-semibold text-body">
                Used for
                <select
                    id={`${idPrefix}-purpose`}
                    className="form-input mt-1 w-full"
                    value={form.data.purpose}
                    aria-invalid={form.errors.purpose ? true : undefined}
                    aria-describedby={form.errors.purpose ? `${idPrefix}-purpose-error` : undefined}
                    onChange={(event) => form.setData('purpose', event.target.value as SenderAddressPurpose)}
                >
                    {purposes.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
            </label>
            <label htmlFor={`${idPrefix}-label`} className="text-sm font-semibold text-body">
                Label (optional)
                <input id={`${idPrefix}-label`} className="form-input mt-1 w-full" value={form.data.label} onChange={(event) => form.setData('label', event.target.value)} />
            </label>
            {form.errors.address && (
                <p id={`${idPrefix}-address-error`} className="text-sm text-danger sm:col-span-3">
                    {form.errors.address}
                </p>
            )}
            {form.errors.purpose && (
                <p id={`${idPrefix}-purpose-error`} className="text-sm text-danger sm:col-span-3">
                    {form.errors.purpose}
                </p>
            )}
            {form.errors.label && <p className="text-sm text-danger sm:col-span-3">{form.errors.label}</p>}
        </div>
    );
}

/** Why an address cannot be tested right now, or null when it can. */
export function addressTestBlocker(address: SenderAddress, domainDisabled: boolean, mailgunConfigured: boolean): string | null {
    if (domainDisabled) return 'Enable the domain to send test emails from this address.';
    if (!address.enabled) return 'Enable this address to send a test email from it.';
    if (!mailgunConfigured) return 'Test emails need the Mailgun API key under Integrations.';
    return null;
}

function AddressRow({
    address,
    purposes,
    testRecipient,
    offerTest,
    testBlocker,
}: {
    address: SenderAddress;
    purposes: PurposeOption[];
    testRecipient: string;
    offerTest: boolean;
    testBlocker: string | null;
}) {
    const [editing, setEditing] = useState(false);
    const [busy, setBusy] = useState(false);
    const editRef = useRef<HTMLButtonElement>(null);
    const returnFocus = useRef(false);
    const form = useForm({ address: address.address, purpose: address.purpose, label: address.label ?? '' });
    const base = `/admin/settings/email-domains/addresses/${address.id}`;

    useEffect(() => {
        if (!editing && returnFocus.current) {
            editRef.current?.focus();
            returnFocus.current = false;
        }
    }, [editing]);

    const close = () => {
        returnFocus.current = true;
        setEditing(false);
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        form.put(base, {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults();
                close();
            },
        });
    };

    const toggle = () => {
        setBusy(true);
        router.post(`${base}/${address.enabled ? 'disable' : 'enable'}`, {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <li className="flex flex-col gap-2 border-t border-border py-3">
            {editing ? (
                <form onSubmit={save} className="flex flex-col gap-2" aria-label={`Edit ${address.address}`}>
                    <AddressFields idPrefix={`sender-address-${address.id}`} form={form} purposes={purposes} placeholder={address.address} autoFocus />
                    <div className="flex flex-wrap gap-2">
                        <button type="submit" className="sg-btn sg-btn-primary sg-btn-sm" disabled={form.processing}>
                            Save address
                        </button>
                        <button
                            type="button"
                            className="sg-btn sg-btn-secondary sg-btn-sm"
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                close();
                            }}
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            ) : (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p className="font-mono text-sm text-body">{address.address}</p>
                        <p className="text-xs text-muted">
                            {purposeLabel(address.purpose, purposes)}
                            {address.label ? ` · ${address.label}` : ''}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={address.enabled ? 'ok' : 'neutral'}>{address.enabled ? 'Enabled' : 'Disabled'}</Badge>
                        <button ref={editRef} type="button" className="sg-btn sg-btn-secondary sg-btn-sm" onClick={() => setEditing(true)} aria-label={`Edit ${address.address}`}>
                            Edit
                        </button>
                        <button
                            type="button"
                            className="sg-btn sg-btn-secondary sg-btn-sm"
                            disabled={busy}
                            onClick={toggle}
                            aria-label={`${address.enabled ? 'Disable' : 'Enable'} ${address.address}`}
                        >
                            {address.enabled ? 'Disable' : 'Enable'}
                        </button>
                    </div>
                </div>
            )}
            <TestSendPanel
                url={`${base}/test`}
                subject={address.address}
                recipient={testRecipient}
                lastTest={address.last_test}
                initiallyOpen={offerTest}
                disabledReason={testBlocker}
            />
        </li>
    );
}

function AddAddressForm({ domainId, domain, purposes }: { domainId: number; domain: string; purposes: PurposeOption[] }) {
    const form = useForm<{ address: string; purpose: SenderAddressPurpose; label: string }>({ address: '', purpose: 'news', label: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/admin/settings/email-domains/${domainId}/addresses`, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-2 border-t border-border pt-3" aria-label={`Add a From address on ${domain}`}>
            <AddressFields idPrefix={`add-sender-address-${domainId}`} form={form} purposes={purposes} placeholder={`news@${domain}`} />
            <button type="submit" className="sg-btn sg-btn-secondary sg-btn-sm self-start" disabled={form.processing || form.data.address.trim() === ''}>
                Add address
            </button>
        </form>
    );
}

export default function SenderAddresses({
    domainId,
    domain,
    addresses,
    purposes,
    testRecipient,
    offerTestAddressId,
    domainDisabled = false,
    mailgunConfigured = true,
}: {
    domainId: number;
    domain: string;
    addresses: SenderAddress[];
    purposes: PurposeOption[];
    testRecipient: string;
    offerTestAddressId: number | null;
    domainDisabled?: boolean;
    mailgunConfigured?: boolean;
}) {
    const headingId = `sender-addresses-${domainId}-heading`;

    return (
        <section aria-labelledby={headingId} className="text-sm">
            <h3 id={headingId} className="font-semibold text-body">
                From addresses
            </h3>
            {addresses.length === 0 ? (
                <p className="text-muted">No From addresses yet. Newsletters cannot send from this domain until one is added.</p>
            ) : (
                <ul className="mt-1">
                    {addresses.map((address) => (
                        <AddressRow
                            key={address.id}
                            address={address}
                            purposes={purposes}
                            testRecipient={testRecipient}
                            offerTest={address.id === offerTestAddressId}
                            testBlocker={addressTestBlocker(address, domainDisabled, mailgunConfigured)}
                        />
                    ))}
                </ul>
            )}
            <AddAddressForm domainId={domainId} domain={domain} purposes={purposes} />
        </section>
    );
}
