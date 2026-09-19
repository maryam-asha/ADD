<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingPresentationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The booking detail screen: the request-status stepper, the price
 * breakdown, the amenities, and what the member can still do.
 *
 * Every composite block is built by BookingPresentationService, so the
 * stepper's state machine and the cancellability predicate have one
 * definition shared with the admin surfaces and with the endpoints that
 * enforce them.
 *
 * @property Booking $resource
 */
class MemberBookingDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();
        $presentation = app(BookingPresentationService::class);
        $booking = $this->resource;
        $space = $booking->space;

        return [
            'id' => $booking->id,
            'space' => [
                'id' => $space->id,
                'title' => $space->translate('name', $locale),
                'space_type' => $space->space_type->value,
                'capacity' => $space->capacity,
                'main_image' => $space->main_image,
                'images' => $space->images,
                'location' => [
                    'branch' => $space->building?->branch?->translate('name', $locale),
                    'building' => $space->building?->translate('name', $locale),
                    'floor' => $space->zone?->floor?->label,
                    'zone' => $space->zone?->label,
                    'formatted' => $this->location($locale),
                ],
            ],
            'when' => $presentation->when($booking->start_at, $booking->end_at),
            'status' => $booking->status->value,
            'status_label' => __('api.booking.statuses.'.$booking->status->value, [], $locale),
            'note' => $booking->note,
            'breakdown' => $presentation->breakdown($space, $booking->start_at, $booking->end_at, $locale),
            'payment' => $presentation->payment($booking, $locale),
            'request_status' => $presentation->requestStatus($booking, $locale),
            'actions' => $presentation->actions($booking, $locale),
            'checked_in_at' => $booking->checked_in_at?->toIso8601String(),
            'checked_out_at' => $booking->checked_out_at?->toIso8601String(),
        ];
    }

    private function location(string $locale): string
    {
        $branch = $this->resource->space->building?->branch?->translate('name', $locale);
        $floor = $this->resource->space->zone?->floor?->label;

        return collect([$branch, $floor])->filter()->implode(' – ');
    }
}
