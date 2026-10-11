<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingPresentationService;
use App\Http\Resources\Concerns\PreloadsAppliedDiscounts;
use App\Support\Presenters\AppliedDiscountPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row in the operations bookings index.
 *
 * Carries the member and the payment state, which the member-facing
 * resources deliberately do not: operations needs to see who booked what
 * and whether it has been settled.
 *
 * @property Booking $resource
 */
class AdminBookingResource extends JsonResource
{
    use PreloadsAppliedDiscounts;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();
        $space = $this->resource->space;

        return [
            'id' => $this->resource->id,
            'space' => [
                'id' => $space?->id,
                'title' => $space?->translate('name', $locale),
                'space_type' => $space?->space_type->value,
                'branch' => $space?->building?->branch?->translate('name', $locale),
            ],
            // Where the member sits: a co-working booking may be placed in a
            // room lent to co-working (docs/decisions/coworking-seat-pool.md).
            'seat' => $space !== null ? app(BookingPresentationService::class)->seat($this->resource->seatingSpace(), $locale) : null,
            'member' => [
                'id' => $this->resource->user?->id,
                'name' => $this->resource->user?->name,
                'phone' => $this->resource->user?->phone,
            ],
            'start_at' => $this->resource->start_at->toIso8601String(),
            'end_at' => $this->resource->end_at->toIso8601String(),
            'status' => $this->resource->status->value,
            'status_label' => __('api.booking.statuses.'.$this->resource->status->value, [], $locale),
            'payment_state' => $this->resource->payment_state->value,
            'payment_source' => $this->resource->payment_source?->value,
            'amount_owed' => $this->resource->amount_owed,
            'currency' => $this->resource->currency,
            'checked_in_at' => $this->resource->checked_in_at?->toIso8601String(),
            'checked_out_at' => $this->resource->checked_out_at?->toIso8601String(),
            'note' => $this->resource->note,
            'rejection_reason' => $this->resource->rejection_reason,
            // What was taken off it — offer, coupon, plan discount — as it
            // was when the price was locked (AppliedDiscountPresenter).
            'discounts' => app(AppliedDiscountPresenter::class)->for($this->resource),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
