<?php

namespace App\Http\Resources;

use App\Domain\Pricing\Models\CouponTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A coupon in the member's "my coupons": its code, what it takes off, until
 * when, how many more uses they have (`uses_left`, null for no limit), and
 * what it applies to (`targets`, empty for everything). Who else it was
 * given to, and how often it was used, are the dashboard's alone.
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
            'max_discount_points' => $this->max_discount_points,
            'min_amount_points' => $this->min_amount_points,
            'combinable_with_offers' => $this->combinable_with_offers,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'uses_left' => $this->uses_left,
            'targets' => $this->targets->map(fn (CouponTarget $target) => [
                'purpose' => $target->purpose,
                'plan_id' => $target->plan_id,
                'space_id' => $target->space_id,
                'space_type' => $target->space_type,
                'event_id' => $target->event_id,
            ]),
        ];
    }
}
