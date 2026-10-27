import { useId } from 'react';

export type WhatsAppType = 'number' | 'username';

export type ContactDetailsValues = {
    address_line1: string | null;
    address_line2: string | null;
    address_town: string | null;
    address_county: string | null;
    address_postcode: string | null;
    address_country: string;
    mobile_number: string | null;
    whatsapp_type: WhatsAppType | null;
    whatsapp_value: string | null;
    whatsapp_same_as_mobile: boolean;
};

export const EMPTY_CONTACT_DETAILS: ContactDetailsValues = {
    address_line1: null,
    address_line2: null,
    address_town: null,
    address_county: null,
    address_postcode: null,
    address_country: 'GB',
    mobile_number: null,
    whatsapp_type: null,
    whatsapp_value: null,
    whatsapp_same_as_mobile: false,
};

/**
 * Form starting values. The radio shows "number" when no type is stored, so
 * the submitted type has to match it.
 */
export function contactFormDefaults(details: ContactDetailsValues | null | undefined): ContactDetailsValues {
    const values = details ?? EMPTY_CONTACT_DETAILS;

    return { ...values, whatsapp_type: values.whatsapp_type ?? 'number' };
}

type Field = keyof ContactDetailsValues;

export type ContactDetailsChange = (field: Field, value: ContactDetailsValues[Field]) => void;

type TextField = Exclude<Field, 'whatsapp_type' | 'whatsapp_same_as_mobile'>;

function TextInput({
    field,
    label,
    values,
    errors,
    onChange,
    autoComplete,
    type = 'text',
    hint,
}: {
    field: TextField;
    label: string;
    values: ContactDetailsValues;
    errors: Partial<Record<string, string>>;
    onChange: ContactDetailsChange;
    autoComplete?: string;
    type?: 'text' | 'tel';
    hint?: string;
}) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const error = errors[field];
    const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div>
            <label htmlFor={id} className="text-sm font-bold text-body">
                {label}
            </label>
            {hint && (
                <p id={hintId} className="text-sm text-muted">
                    {hint}
                </p>
            )}
            <input
                id={id}
                type={type}
                value={values[field] ?? ''}
                onChange={(e) => onChange(field, e.target.value)}
                autoComplete={autoComplete}
                aria-invalid={error ? true : undefined}
                aria-describedby={describedBy}
                className="form-input mt-1"
            />
            {error && (
                <p id={errorId} className="text-sm text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * Optional private contact fields (UK postal address, mobile and WhatsApp).
 * Never render these on public profile pages.
 */
export default function ContactDetailsFields({
    values,
    errors,
    onChange,
}: {
    values: ContactDetailsValues;
    errors: Partial<Record<string, string>>;
    onChange: ContactDetailsChange;
}) {
    const whatsappTypeName = useId();
    const whatsappType: WhatsAppType = values.whatsapp_type ?? 'number';
    const shared = { values, errors, onChange };

    return (
        <div className="flex flex-col gap-4">
            <fieldset className="flex flex-col gap-3">
                <legend className="text-sm font-bold text-body">Postal address</legend>
                <TextInput field="address_line1" label="Address line 1" autoComplete="address-line1" {...shared} />
                <TextInput field="address_line2" label="Address line 2 (optional)" autoComplete="address-line2" {...shared} />
                <TextInput field="address_town" label="Town or city" autoComplete="address-level2" {...shared} />
                <TextInput field="address_county" label="County (optional)" autoComplete="address-level1" {...shared} />
                <TextInput field="address_postcode" label="Postcode" autoComplete="postal-code" {...shared} />
                <TextInput
                    field="address_country"
                    label="Country code"
                    hint="Two letters, for example GB."
                    autoComplete="country"
                    {...shared}
                />
            </fieldset>

            <TextInput
                field="mobile_number"
                label="Mobile number"
                type="tel"
                hint="UK numbers can start with 07. We store numbers in international format."
                autoComplete="tel"
                {...shared}
            />

            <fieldset className="flex flex-col gap-3">
                <legend className="text-sm font-bold text-body">WhatsApp</legend>
                <label className="flex items-center gap-2 text-sm text-body">
                    <input
                        type="checkbox"
                        checked={values.whatsapp_same_as_mobile}
                        onChange={(e) => onChange('whatsapp_same_as_mobile', e.target.checked)}
                    />
                    Same as mobile number
                </label>
                {errors.whatsapp_same_as_mobile && <p className="text-sm text-danger">{errors.whatsapp_same_as_mobile}</p>}

                {!values.whatsapp_same_as_mobile && (
                    <>
                        <div role="radiogroup" aria-label="WhatsApp contact type" className="flex flex-wrap gap-4 text-sm text-body">
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name={whatsappTypeName}
                                    checked={whatsappType === 'number'}
                                    onChange={() => onChange('whatsapp_type', 'number')}
                                />
                                Phone number
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="radio"
                                    name={whatsappTypeName}
                                    checked={whatsappType === 'username'}
                                    onChange={() => onChange('whatsapp_type', 'username')}
                                />
                                Username
                            </label>
                        </div>
                        {errors.whatsapp_type && <p className="text-sm text-danger">{errors.whatsapp_type}</p>}
                        <TextInput
                            field="whatsapp_value"
                            label={whatsappType === 'username' ? 'WhatsApp username' : 'WhatsApp number'}
                            type={whatsappType === 'username' ? 'text' : 'tel'}
                            {...shared}
                        />
                    </>
                )}
            </fieldset>
        </div>
    );
}
