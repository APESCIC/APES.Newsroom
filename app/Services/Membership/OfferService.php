<?php

namespace App\Services\Membership;

use App\Models\Offer;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OfferService
{
    public function __construct(private readonly StripeBillingClient $stripe) {}

    /**
     * @param  array{
     *     code: string,
     *     name: string,
     *     discount_type: string,
     *     discount_value: int,
     *     starts_at?: ?string,
     *     ends_at?: ?string,
     *     max_redemptions?: ?int,
     *     is_active?: bool
     * }  $attributes
     */
    public function create(array $attributes): Offer
    {
        $offer = new Offer([
            'code' => $attributes['code'],
            'name' => $attributes['name'],
            'discount_type' => $attributes['discount_type'],
            'discount_value' => $attributes['discount_value'],
            'starts_at' => $attributes['starts_at'] ?? null,
            'ends_at' => $attributes['ends_at'] ?? null,
            'max_redemptions' => $attributes['max_redemptions'] ?? null,
            'is_active' => $attributes['is_active'] ?? true,
        ]);

        $stripeIds = $this->stripe->createOfferPromotion($offer);
        $offer->stripe_coupon_id = $stripeIds['coupon_id'];
        $offer->stripe_promotion_code_id = $stripeIds['promotion_code_id'];
        $offer->save();

        return $offer;
    }

    public function resolveValidOffer(string $code): Offer
    {
        $offer = Offer::query()->where('code', Str::upper(trim($code)))->first();

        if (! $offer || ! $offer->isCurrentlyValid()) {
            throw new InvalidArgumentException('That offer code is invalid, expired, or exhausted.');
        }

        if (blank($offer->stripe_promotion_code_id)) {
            throw new InvalidArgumentException('That offer is not linked to Stripe yet.');
        }

        return $offer;
    }
}
