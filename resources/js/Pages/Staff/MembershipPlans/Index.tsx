import { Head, useForm } from '@inertiajs/react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../Components/Workspace/ui/Badge';

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
            aria-label={`Plan ${plan.slug}`}
            className="sg-card flex flex-col gap-4 p-5 sm:p-6"
            onSubmit={(e) => {
                e.preventDefault();
                patch(`/staff/membership-plans/${plan.id}`);
            }}
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-base font-semibold text-body">{plan.slug}</h2>
                <div className="flex gap-2">
                    <Badge tone="info">{plan.interval}</Badge>
                    {plan.is_active ? <Badge tone="ok">Active</Badge> : <Badge tone="neutral">Inactive</Badge>}
                </div>
            </div>
            <div className="grid gap-4 md:grid-cols-3">
                <label className="text-sm font-semibold text-body">
                    Name
                    <input className="form-input mt-1.5" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    {errors.name && <span className="mt-1 block text-sm text-danger">{errors.name}</span>}
                </label>
                <label className="text-sm font-semibold text-body">
                    Amount (pence)
                    <input type="number" className="form-input mt-1.5" value={data.amount_pence} onChange={(e) => setData('amount_pence', Number(e.target.value))} />
                    {errors.amount_pence && <span className="mt-1 block text-sm text-danger">{errors.amount_pence}</span>}
                </label>
                <label className="text-sm font-semibold text-body">
                    Stripe price id (test mode)
                    <input className="form-input mt-1.5" value={data.stripe_price_id} onChange={(e) => setData('stripe_price_id', e.target.value)} placeholder="price_..." />
                    {errors.stripe_price_id && <span className="mt-1 block text-sm text-danger">{errors.stripe_price_id}</span>}
                </label>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <label className="flex items-center gap-2 text-sm text-body">
                    <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                    Active
                </label>
                <button type="submit" className="sg-btn sg-btn-primary" disabled={processing}>
                    Save plan
                </button>
            </div>
        </form>
    );
}

export default function Index({ plans }: { plans: Plan[] }) {
    return (
        <WorkspaceLayout
            area="Staff"
            active="membership-plans"
            title="Membership plans"
            subtitle="Configure monthly and yearly supporter tiers. Use Stripe test-mode price ids only until live charges are authorized."
        >
            <Head title="Membership plans" />
            {plans.map((plan) => (
                <PlanRow key={plan.id} plan={plan} />
            ))}
        </WorkspaceLayout>
    );
}
