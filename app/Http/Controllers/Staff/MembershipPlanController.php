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
    public function index(): Response
    {
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
                    'stripe_price_id' => $plan->stripe_price_id,
                    'is_active' => $plan->is_active,
                ]),
        ]);
    }

    public function update(Request $request, MembershipPlan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount_pence' => ['required', 'integer', 'min:0'],
            'stripe_price_id' => ['nullable', 'string', 'max:191'],
            'is_active' => ['required', 'boolean'],
        ]);

        $plan->update($validated);

        return redirect()->route('staff.membership-plans.index');
    }
}
