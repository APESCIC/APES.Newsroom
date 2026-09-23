<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\MembershipPlan;
use App\Services\Membership\MembershipBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MembershipCheckoutController extends Controller
{
    public function __construct(private readonly MembershipBillingService $billing) {}

    public function checkout(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'exists:membership_plans,slug'],
        ]);

        $plan = MembershipPlan::query()
            ->where('slug', $validated['plan'])
            ->where('is_active', true)
            ->firstOrFail();

        try {
            $url = $this->billing->startCheckout($request->user(), $plan);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['plan' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function portal(Request $request): RedirectResponse
    {
        try {
            $url = $this->billing->startBillingPortal($request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['membership' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function success(Request $request): RedirectResponse
    {
        return redirect()
            ->route('account.show')
            ->with('status', 'membership-checkout-started');
    }
}
