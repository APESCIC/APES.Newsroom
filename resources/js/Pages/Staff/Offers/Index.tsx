import { Head, useForm } from '@inertiajs/react';
import { Tag } from 'lucide-react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';
import EmptyState from '../../../Components/Workspace/ui/EmptyState';
import { FormSection } from '../../../Components/Workspace/ui/FormField';

type OfferRow = {
    id: number;
    code: string;
    name: string;
    discount_type: string;
    discount_value: number;
    starts_at: string | null;
    ends_at: string | null;
    max_redemptions: number | null;
    redemption_count: number;
    is_active: boolean;
    is_valid: boolean;
};

function CreateOfferForm() {
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        name: '',
        discount_type: 'percent',
        discount_value: 10,
        starts_at: '',
        ends_at: '',
        max_redemptions: '',
        is_active: true,
    });

    return (
        <FormSection title="Create offer" description="Codes are case-insensitive and apply at membership checkout.">
            <form
                className="flex flex-col gap-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    post('/staff/offers', {
                        onSuccess: () => reset(),
                    });
                }}
            >
                <div className="grid gap-4 md:grid-cols-2">
                    <label className="text-sm font-semibold text-body">
                        Code
                        <input className="form-input mt-1.5" value={data.code} onChange={(e) => setData('code', e.target.value)} required />
                        {errors.code && <span className="mt-1 block text-sm text-danger">{errors.code}</span>}
                    </label>
                    <label className="text-sm font-semibold text-body">
                        Name
                        <input className="form-input mt-1.5" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    </label>
                    <label className="text-sm font-semibold text-body">
                        Type
                        <select className="form-input mt-1.5" value={data.discount_type} onChange={(e) => setData('discount_type', e.target.value)}>
                            <option value="percent">Percent off</option>
                            <option value="amount">Amount off (pence)</option>
                        </select>
                    </label>
                    <label className="text-sm font-semibold text-body">
                        Value
                        <input
                            type="number"
                            className="form-input mt-1.5"
                            value={data.discount_value}
                            onChange={(e) => setData('discount_value', Number(e.target.value))}
                            required
                        />
                    </label>
                    <label className="text-sm font-semibold text-body">
                        Starts
                        <input type="datetime-local" className="form-input mt-1.5" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                    </label>
                    <label className="text-sm font-semibold text-body">
                        Ends
                        <input type="datetime-local" className="form-input mt-1.5" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                    </label>
                    <label className="text-sm font-semibold text-body">
                        Max redemptions
                        <input type="number" className="form-input mt-1.5" value={data.max_redemptions} onChange={(e) => setData('max_redemptions', e.target.value)} />
                    </label>
                </div>
                <button type="submit" className="sg-btn sg-btn-primary self-start" disabled={processing}>
                    Create offer
                </button>
            </form>
        </FormSection>
    );
}

function OfferRowForm({ offer }: { offer: OfferRow }) {
    const { data, setData, patch, processing } = useForm({
        name: offer.name,
        is_active: offer.is_active,
        ends_at: offer.ends_at ? offer.ends_at.slice(0, 16) : '',
        max_redemptions: offer.max_redemptions ?? '',
    });

    return (
        <li>
            <form
                aria-label={`Offer ${offer.code}`}
                className="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_auto] md:items-end"
                onSubmit={(e) => {
                    e.preventDefault();
                    patch(`/staff/offers/${offer.id}`);
                }}
            >
                <div>
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="font-semibold text-body">{offer.code}</h3>
                        {offer.is_valid ? <Badge tone="ok">Valid</Badge> : <Badge tone="neutral">Unavailable</Badge>}
                    </div>
                    <p className="mt-1 text-sm text-muted">
                        {offer.discount_type === 'percent' ? `${offer.discount_value}% off` : `${offer.discount_value}p off`}
                        {' · '}
                        {offer.redemption_count}
                        {offer.max_redemptions !== null ? ` / ${offer.max_redemptions}` : ''} redemptions
                    </p>
                </div>
                <label className="text-sm font-semibold text-body">
                    Name
                    <input className="form-input mt-1.5" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                </label>
                <div className="flex items-center gap-3">
                    <label className="flex items-center gap-2 text-sm text-body">
                        <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                        Active
                    </label>
                    <button type="submit" className="sg-btn sg-btn-secondary sg-btn-sm" disabled={processing}>
                        Save
                    </button>
                </div>
            </form>
        </li>
    );
}

export default function Index({ offers }: { offers: OfferRow[] }) {
    return (
        <WorkspaceLayout
            area="Staff"
            active="offers"
            title="Offers"
            subtitle="Time-bound or usage-limited promo codes applied at membership checkout. Stripe test-mode coupons are created when billing keys are configured."
        >
            <Head title="Offers" />
            <CreateOfferForm />
            {offers.length === 0 ? (
                <EmptyState icon={Tag} title="No offers yet" description="Create a code above to offer a membership discount." />
            ) : (
                <section aria-label="Existing offers" className="sg-card">
                    <h2 className="px-5 pt-5 text-base font-semibold text-body">Existing offers</h2>
                    <ul className="divide-y divide-border">
                        {offers.map((offer) => (
                            <OfferRowForm key={offer.id} offer={offer} />
                        ))}
                    </ul>
                </section>
            )}
        </WorkspaceLayout>
    );
}
