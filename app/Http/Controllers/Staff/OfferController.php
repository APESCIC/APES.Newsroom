<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Services\Membership\OfferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfferController extends Controller
{
    public function __construct(private readonly OfferService $offers) {}

    public function index(): Response
    {
        return Inertia::render('Staff/Offers/Index', [
            'offers' => Offer::query()
                ->latest()
                ->get()
                ->map(fn (Offer $offer) => [
                    'id' => $offer->id,
                    'code' => $offer->code,
                    'name' => $offer->name,
                    'discount_type' => $offer->discount_type,
                    'discount_value' => $offer->discount_value,
                    'starts_at' => $offer->starts_at?->toIso8601String(),
                    'ends_at' => $offer->ends_at?->toIso8601String(),
                    'max_redemptions' => $offer->max_redemptions,
                    'redemption_count' => $offer->redemption_count,
                    'is_active' => $offer->is_active,
                    'is_valid' => $offer->isCurrentlyValid(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'unique:offers,code'],
            'name' => ['required', 'string', 'max:120'],
            'discount_type' => ['required', 'in:percent,amount'],
            'discount_value' => ['required', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validated['discount_type'] === 'percent' && $validated['discount_value'] > 100) {
            return back()->withErrors(['discount_value' => 'Percent off cannot exceed 100.']);
        }

        $this->offers->create($validated);

        return redirect()->route('staff.offers.index');
    }

    public function update(Request $request, Offer $offer): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'ends_at' => ['nullable', 'date'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
        ]);

        $offer->update($validated);

        return redirect()->route('staff.offers.index');
    }
}
