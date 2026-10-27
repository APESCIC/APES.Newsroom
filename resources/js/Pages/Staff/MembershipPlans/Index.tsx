import { useForm } from '@inertiajs/react';
import WorkspaceLayout from '../../../Components/Layout/WorkspaceLayout';
import AdvancedDetails from '../../../Components/Workspace/ui/AdvancedDetails';
import Badge from '../../../Components/Workspace/ui/Badge';

type Plan = {
    id: number;
    name: string;
    slug: string;
    interval: string;
    amount_pence: number;
    currency: string;
    /** Only sent to admins (#357). */
    stripe_price_id?: string | null;
    is_active: boolean;
};

type PlanForm = {
    name: string;
    amount_pence: number;
    is_active: boolean;
    stripe_price_id?: string;
};

function PlanRow({ plan, canManageStripe }: { plan: Plan; canManageStripe: boolean }) {
    const { data, setData, patch, processing, errors } = useForm<PlanForm>({
        name: plan.name,
        amount_pence: plan.amount_pence,
        is_active: plan.is_active,
        ...(canManageStripe ? { stripe_price_id: plan.stripe_price_id ?? '' } : {}),
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
            <div className="grid gap-4 md:grid-cols-2">
                <label className="text-sm font-semibold text-body">
                    Name
                    <input className="form-input mt-1.5" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    {errors.name && <span className="mt-1 block text-sm text-danger">{errors.name}</span>}
                </label>
                <label className="text-sm font-semibold text-body">
                    Amount (pence)
                    <input
                        type="number"
                        className="form-input mt-1.5"
                        value={data.amount_pence}
                        onChange={(e) => setData('amount_pence', Number(e.target.value))}
                    />
                    {errors.amount_pence && <span className="mt-1 block text-sm text-danger">{errors.amount_pence}</span>}
                </label>
            </div>
            {canManageStripe && (
                <AdvancedDetails summary="Stripe billing">
                    <label className="text-sm font-semibold text-body">
                        Stripe price id (test mode)
                        <input
                            className="form-input mt-1.5"
                            value={data.stripe_price_id ?? ''}
                            onChange={(e) => setData('stripe_price_id', e.target.value)}
                            placeholder="price_..."
                        />
                        {errors.stripe_price_id && <span className="mt-1 block text-sm text-danger">{errors.stripe_price_id}</span>}
                    </label>
                    <p className="text-muted">Use Stripe test-mode price ids only until live charges are authorised.</p>
                </AdvancedDetails>
            )}
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

export default function Index({ plans, canManageStripe = false }: { plans: Plan[]; canManageStripe?: boolean }) {
    return (
        <WorkspaceLayout
            area="Staff"
            active="membership-plans"
            title="Membership plans"
            subtitle="Configure the monthly and yearly supporter tiers members can join."
        >
            {plans.map((plan) => (
                <PlanRow key={plan.id} plan={plan} canManageStripe={canManageStripe} />
            ))}
        </WorkspaceLayout>
    );
}
