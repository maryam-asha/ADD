<?php

namespace App\Http\Resources;

use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\ValueObjects\CouponBesideOffer;
use App\Support\Presenters\PriceLabelPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A coupon in the member's "my coupons": its code, what it takes off, until
 * when, and how many more uses they have (`uses_left`, null for no limit).
 * What it applies to is not listed: asked about an item, `can_apply` says
 * whether it is one of them. Who else it was given to, and how often it was
 * used, are the dashboard's alone.
 */
class MemberCouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->translate('name'),
            'description' => $this->description === null ? null : $this->translate('description'),
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            // A flat amount off is points; this is the same in the display currency. Null for a percentage.
            'discount_amount' => $this->discount_type === CouponDiscountType::AmountOff
                ? app(PriceLabelPresenter::class)->money((int) $this->discount_value, $request->user())
                : null,
            'max_discount_points' => $this->max_discount_points,
            'min_amount_points' => $this->min_amount_points,
            'combinable_with_offers' => $this->combinable_with_offers,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'uses_left' => $this->uses_left,
            // "My coupons" only, where both are always set: whether it can be used on the item asked
            // about (true with none), and beside its offer — null unless it can and an item was asked about.
            ...(array_key_exists('with_offer', $this->resource->getAttributes())
                ? [
                    'can_apply' => (bool) $this->resource->getAttribute('can_apply'),
                    'with_offer' => $this->withOffer($this->resource->getAttribute('with_offer')),
                ]
                : []),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function withOffer(?CouponBesideOffer $beside): ?array
    {
        if ($beside === null) {
            return null;
        }

        $note = $beside->note();

        return [
            'offer' => $beside->offer === null ? null : [
                'name' => $beside->offer->offer->translate('name'),
                'original_points' => $beside->originalPoints,
                'points' => $beside->offer->points,
            ],
            'combines' => $beside->combines,
            'price_points' => $beside->pricePoints,
            'applied' => $beside->applied,
            'note' => $note === null ? null : __("api.coupon.with_offer.{$note}"),
        ];
    }
}
