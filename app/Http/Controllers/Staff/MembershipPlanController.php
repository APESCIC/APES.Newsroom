<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\MembershipPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MembershipPlanController extends Controller
{
    public function index(Request $request): Response
    {
        $showStripe = $this->canManageStripe($request);

        return Inertia::render('Staff/MembershipPlans/Index', [
            'plans' => MembershipPlan::query()
                ->orderBy('interval')
                ->get()
                ->map(fn (MembershipPlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'interval' => $plan->interval,
                    'amount_pence' => $plan->amount_pence,
                    'currency' => $plan->currency,
                    'is_active' => $plan->is_active,
                    ...($showStripe ? ['stripe_price_id' => $plan->stripe_price_id] : []),
                ]),
            'canManageStripe' => $showStripe,
        ]);
    }

    public function update(Request $request, MembershipPlan $plan): RedirectResponse
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'amount_pence' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ];

        // Stripe price ids are admin-only (#357); a staff value is dropped by validate().
        if ($this->canManageStripe($request)) {
            $rules['stripe_price_id'] = ['nullable', 'string', 'max:191'];
        }

        $plan->update($request->validate($rules));

        return redirect()->route('staff.membership-plans.index');
    }

    private function canManageStripe(Request $request): bool
    {
        return $request->user()->can('access-admin-area');
    }
}
