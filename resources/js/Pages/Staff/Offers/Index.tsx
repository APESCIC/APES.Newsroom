import { Head, useForm } from '@inertiajs/react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

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
        <form
            className="glass-form-panel flex flex-col gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                post('/staff/offers', {
                    onSuccess: () => reset(),
                });
            }}
        >
            <h2 className="text-lg font-bold text-body">Create offer</h2>
            <label className="text-sm font-bold text-body">
                Code
                <input className="form-input mt-1" value={data.code} onChange={(e) => setData('code', e.target.value)} required />
            </label>
            {errors.code && <p className="text-sm text-danger">{errors.code}</p>}
            <label className="text-sm font-bold text-body">
                Name
                <input className="form-input mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
            </label>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm font-bold text-body">
                    Type
                    <select className="form-input mt-1" value={data.discount_type} onChange={(e) => setData('discount_type', e.target.value)}>
                        <option value="percent">Percent off</option>
                        <option value="amount">Amount off (pence)</option>
                    </select>
                </label>
                <label className="text-sm font-bold text-body">
                    Value
                    <input
                        type="number"
                        className="form-input mt-1"
                        value={data.discount_value}
                        onChange={(e) => setData('discount_value', Number(e.target.value))}
                        required
                    />
                </label>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-sm font-bold text-body">
                    Starts
                    <input type="datetime-local" className="form-input mt-1" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                </label>
                <label className="text-sm font-bold text-body">
                    Ends
                    <input type="datetime-local" className="form-input mt-1" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                </label>
            </div>
            <label className="text-sm font-bold text-body">
                Max redemptions
                <input
                    type="number"
                    className="form-input mt-1"
                    value={data.max_redemptions}
                    onChange={(e) => setData('max_redemptions', e.target.value)}
                />
            </label>
            <button type="submit" className="button-primary w-fit" disabled={processing}>
                Create offer
            </button>
        </form>
    );
}

function OfferCard({ offer }: { offer: OfferRow }) {
    const { data, setData, patch, processing } = useForm({
        name: offer.name,
        is_active: offer.is_active,
        ends_at: offer.ends_at ? offer.ends_at.slice(0, 16) : '',
        max_redemptions: offer.max_redemptions ?? '',
    });

    return (
        <form
            className="glass-form-panel flex flex-col gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                patch(`/staff/offers/${offer.id}`);
            }}
        >
            <div className="flex items-baseline justify-between gap-3">
                <h2 className="text-lg font-bold text-body">{offer.code}</h2>
                <p className="text-sm text-muted">{offer.is_valid ? 'Valid' : 'Unavailable'}</p>
            </div>
            <p className="text-sm text-muted">
                {offer.discount_type === 'percent' ? `${offer.discount_value}% off` : `${offer.discount_value}p off`}
                {' · '}
                {offer.redemption_count}
                {offer.max_redemptions !== null ? ` / ${offer.max_redemptions}` : ''} redemptions
            </p>
            <label className="text-sm font-bold text-body">
                Name
                <input className="form-input mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} />
            </label>
            <label className="flex items-center gap-2 text-sm text-body">
                <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                Active
            </label>
            <button type="submit" className="button-primary w-fit" disabled={processing}>
                Save
            </button>
        </form>
    );
}

export default function Index({ offers }: { offers: OfferRow[] }) {
    return (
        <WorkspaceLayout area="Staff" active="offers" title="Offers">
            <Head title="Offers" />
            <div className="mx-auto max-w-3xl flex flex-col gap-6">
                <p className="text-sm text-muted">
                    Time-bound or usage-limited promo codes applied at membership checkout. Stripe test-mode coupons are created when billing keys are configured.
                </p>
                <CreateOfferForm />
                <div className="flex flex-col gap-4">
                    {offers.map((offer) => (
                        <OfferCard key={offer.id} offer={offer} />
                    ))}
                </div>
            </div>
        </WorkspaceLayout>
    );
}
