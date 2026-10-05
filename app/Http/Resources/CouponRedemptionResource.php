<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One use of a coupon: who, on what, and the terms it was locked in on.
 * `counts` says whether it still counts against the coupon's limits — set
 * by the controller from CouponRedemption::scopeLive, since that follows
 * the record's status rather than a column here.
 */
class CouponRedemptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ] : null),
            'purpose' => $this->purpose,
            'booking_id' => $this->booking_id,
            'walkin_session_id' => $this->walkin_session_id,
            'event_attendee_id' => $this->event_attendee_id,
            'payment_id' => $this->payment_id,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'max_discount_points' => $this->max_discount_points,
            'base_points' => $this->base_points,
            'points_discounted' => $this->points_discounted,
            'counts' => (bool) $this->getAttribute('counts'),
            'created_at' => $this->created_at,
        ];
    }
}
