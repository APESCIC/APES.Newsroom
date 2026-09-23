import { Head, useForm } from '@inertiajs/react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';

type Plan = {
    id: number;
    name: string;
    slug: string;
    interval: string;
    amount_pence: number;
    currency: string;
    stripe_price_id: string | null;
    is_active: boolean;
};

function PlanRow({ plan }: { plan: Plan }) {
    const { data, setData, patch, processing, errors } = useForm({
        name: plan.name,
        amount_pence: plan.amount_pence,
        stripe_price_id: plan.stripe_price_id ?? '',
        is_active: plan.is_active,
    });

    return (
        <form
            className="glass-form-panel flex flex-col gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                patch(`/staff/membership-plans/${plan.id}`);
            }}
        >
            <div className="flex items-baseline justify-between gap-3">
                <h2 className="text-lg font-bold text-body">{plan.slug}</h2>
                <p className="text-sm text-muted">{plan.interval}</p>
            </div>
            <label className="text-sm font-bold text-body">
                Name
                <input className="form-input mt-1" value={data.name} onChange={(e) => setData('name', e.target.value)} />
            </label>
            {errors.name && <p className="text-sm text-danger">{errors.name}</p>}
            <label className="text-sm font-bold text-body">
                Amount (pence)
                <input
                    type="number"
                    className="form-input mt-1"
                    value={data.amount_pence}
                    onChange={(e) => setData('amount_pence', Number(e.target.value))}
                />
            </label>
            {errors.amount_pence && <p className="text-sm text-danger">{errors.amount_pence}</p>}
            <label className="text-sm font-bold text-body">
                Stripe price id (test mode)
                <input
                    className="form-input mt-1"
                    value={data.stripe_price_id}
                    onChange={(e) => setData('stripe_price_id', e.target.value)}
                    placeholder="price_..."
                />
            </label>
            {errors.stripe_price_id && <p className="text-sm text-danger">{errors.stripe_price_id}</p>}
            <label className="flex items-center gap-2 text-sm text-body">
                <input
                    type="checkbox"
                    checked={data.is_active}
                    onChange={(e) => setData('is_active', e.target.checked)}
                />
                Active
            </label>
            <button type="submit" className="button-primary w-fit" disabled={processing}>
                Save plan
            </button>
        </form>
    );
}

export default function Index({ plans }: { plans: Plan[] }) {
    return (
        <WorkspaceLayout area="Staff" active="membership-plans" title="Membership plans">
            <Head title="Membership plans" />
            <div className="mx-auto max-w-3xl">
                <p className="text-sm text-muted">
                    Configure monthly and yearly supporter tiers. Use Stripe test-mode price ids only until live charges are authorized.
                </p>
                <div className="mt-6 flex flex-col gap-4">
                    {plans.map((plan) => (
                        <PlanRow key={plan.id} plan={plan} />
                    ))}
                </div>
            </div>
        </WorkspaceLayout>
    );
}
