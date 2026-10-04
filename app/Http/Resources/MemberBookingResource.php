<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingPresentationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card in the member's bookings list: what the card shows and nothing
 * more — the space's title and picture, the day and time lines, the status
 * badge, and whether it is paid.
 *
 * Everything else (the breakdown, the payment details, `status_note`,
 * cancel and extend) is MemberBookingDetailResource's. The list renders
 * dozens of these, and every field here is one the app draws; the badge's
 * "pending payment" is `confirmed` with `payment_state` unpaid, read by the
 * app rather than a third value sent here.
 *
 * @property Booking $resource
 */
class MemberBookingResource extends JsonResource
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
                'title' => $space->displayTitle($locale),
                'main_image' => $space->main_image,
            ],
            'date_label' => $presentation->dateLabel($booking->start_at, $locale),
            'time_label' => $presentation->timeLabel($booking->start_at, $booking->end_at, $locale),
            // Until it starts — a booking under way is no longer upcoming.
            'is_upcoming' => $booking->start_at->isFuture(),
            'status' => $booking->status->value,
            'status_label' => $booking->status->label($locale),
            'payment_state' => $booking->payment_state->value,
        ];
    }
}
