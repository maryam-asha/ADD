<?php

namespace App\Http\Resources;

use App\Domain\Pricing\Models\CouponTarget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A coupon on the dashboard, as the admin wrote it. `used` is how many uses
 * still count against its limits — a cancelled booking's use gave itself
 * back (AppliedDiscount::scopeLive). `locked`: it has been used at all, so
 * it can only be switched on or off — never edited or deleted.
 */
class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'is_automatic' => (bool) $this->is_automatic,
            'name' => $this->name,
            'description' => $this->description,
            'source' => $this->source,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'max_discount_points' => $this->max_discount_points,
            'min_amount_points' => $this->min_amount_points,
            'combinable_with_offers' => $this->combinable_with_offers,
            'usage_limit' => $this->usage_limit,
            'per_user_limit' => $this->per_user_limit,
            'new_members_only' => $this->new_members_only,
            'company_id' => $this->company_id,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'is_active' => $this->is_active,
            'used' => $this->whenHas('live_redemptions_count'),
            'locked' => $this->whenHas('redemptions_exists', fn () => (bool) $this->redemptions_exists),
            'targets' => $this->whenLoaded('targets', fn () => $this->targets->map(fn (CouponTarget $target) => [
                'id' => $target->id,
                'purpose' => $target->purpose,
                'plan_id' => $target->plan_id,
                'space_id' => $target->space_id,
                'space_type' => $target->space_type,
                'event_id' => $target->event_id,
            ])),
            'user_ids' => $this->whenLoaded('users', fn () => $this->users->pluck('id')),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
