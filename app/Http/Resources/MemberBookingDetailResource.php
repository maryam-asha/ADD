<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingPresentationService;
use App\Support\Presenters\PriceLabelPresenter;
use App\Support\Time\LocalTime;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The booking detail screens — submitted, confirmed, rejected, the cancel
 * sheet — and what they draw, nothing more
 * (docs/decisions/booking-outcomes-and-extension.md): the booking's number,
 * its status, money note and stepper, the space, the day and time, the
 * total, whether it is paid (and the payment to follow), what the member may
 * still do, and what cancelling would give back.
 *
 * Each screen's headline and wording is the app's, chosen from `status`.
 * Every composite block is built by BookingPresentationService, so the
 * stepper, the cancellability predicate and the refund quote have one
 * definition shared with the endpoints that enforce them.
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
        $prices = app(PriceLabelPresenter::class);
        $booking = $this->resource;
        $space = $booking->space;
        $viewer = $request->user();

        // What the booking costs: its own price, discount included, as
        // payment settles and refunds it. A booking made before
        // `price_points` existed is priced again from its window and its own
        // redemption row, never a re-resolved discount.
        $total = $booking->price_points
            ?? $presentation->breakdown($space, $booking->start_at, $booking->end_at, $locale, booking: $booking)['total']['points'];
        $actions = $presentation->actions($booking, $locale);

        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label($locale),
            'status_note' => $presentation->statusNote($booking, $locale),
            'space' => [
                // Kept so the app can tell the space booked from `seat`, the
                // room a co-working booking may actually sit in.
                'id' => $space->id,
                'title' => $space->displayTitle($locale),
                'main_image' => $space->main_image,
                'branch' => $space->building?->branch?->translate('name', $locale),
                'capacity' => $space->capacity,
                'hourly_price' => $space->hourly_rate_points !== null
                    ? $prices->hourly((int) $space->hourly_rate_points, $viewer, $locale)['price_label']
                    : null,
            ],
            // Where to go on arrival: a co-working booking may sit in a room
            // lent to co-working rather than the space it was booked on.
            'seat' => $presentation->seat($booking->seatingSpace(), $locale),
            'date_label' => $presentation->dateLabel($booking->start_at, $locale),
            'time_label' => $presentation->timeLabel($booking->start_at, $booking->end_at, $locale),
            'total' => [
                'points' => $total,
                'price' => $total > 0 ? $prices->total($total, $viewer, $locale)['price'] : null,
            ],
            // The status stays `confirmed` until checkout, so these are what
            // tell a booking under way from one still to come.
            'checked_in_at' => $this->local($booking->checked_in_at),
            'checked_out_at' => $this->local($booking->checked_out_at),
            'payment_state' => $booking->payment_state->value,
            ...$presentation->paymentTracking($booking),
            'request_status' => $presentation->requestStatus($booking, $locale),
            'actions' => [
                'can_cancel' => $actions['can_cancel'],
                'can_extend' => $actions['can_extend'],
                'extend_disabled_reason' => $actions['extend_disabled_reason'],
            ],
            'cancellation' => $presentation->cancellation($booking, $locale, $viewer),
        ];
    }

    /** ISO-8601 in the district's offset, like every other time the app reads. */
    private function local(?CarbonInterface $instant): ?string
    {
        return $instant === null ? null : app(LocalTime::class)->toLocal($instant)?->toIso8601String();
    }
}
