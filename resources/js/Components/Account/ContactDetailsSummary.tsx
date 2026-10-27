import type { ContactDetailsValues } from './ContactDetailsFields';

function whatsappSummary(details: ContactDetailsValues): string | null {
    if (details.whatsapp_same_as_mobile) return 'Same as mobile';
    if (!details.whatsapp_value) return null;

    return details.whatsapp_type === 'username' ? `@${details.whatsapp_value}` : details.whatsapp_value;
}

export default function ContactDetailsSummary({ details }: { details: ContactDetailsValues }) {
    const address = [
        details.address_line1,
        details.address_line2,
        details.address_town,
        details.address_county,
        details.address_postcode,
        details.address_line1 || details.address_postcode ? details.address_country : null,
    ].filter(Boolean);
    const whatsapp = whatsappSummary(details);

    return (
        <dl className="grid gap-3 text-sm sm:grid-cols-3">
            <div>
                <dt className="text-muted">Postal address</dt>
                <dd className="text-body">
                    {address.length === 0 ? 'Not given' : address.map((line) => <span key={line} className="block">{line}</span>)}
                </dd>
            </div>
            <div>
                <dt className="text-muted">Mobile</dt>
                <dd className="text-body">{details.mobile_number ?? 'Not given'}</dd>
            </div>
            <div>
                <dt className="text-muted">WhatsApp</dt>
                <dd className="text-body">{whatsapp ?? 'Not given'}</dd>
            </div>
        </dl>
    );
}
