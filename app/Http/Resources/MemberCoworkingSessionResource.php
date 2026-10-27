<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\BookingPresentationService;
use App\Http\Resources\Concerns\PreloadsAppliedDiscounts;
use App\Support\Presenters\AppliedDiscountPresenter;
use App\Support\Presenters\PaymentStatePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One card in the member's past coworking sessions: the space, the day and
 * the hours it ran, how long, what it came to and whether it is paid
 * (`payment`, as everywhere a session is shown), and what was taken off it.
 *
 * Everything else — the report's lines, the plan, every attempt to pay — is
 * the session's detail (CoworkingSessionController::show()), which starts
 * from these same fields.
 *
 * @property Booking|WalkinSession $resource
 */
class MemberCoworkingSessionResource extends JsonResource
{
    use PreloadsAppliedDiscounts;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();
        $presentation = app(BookingPresentationService::class);
        $session = $this->resource;
        $end = $session->checked_out_at;

        return [
            'type' => $session->coworkingType(),
            'id' => $session->id,
            'reference' => $session->reference,
            'space' => [
                'id' => $session->space->id,
                'name' => $session->space->translate('name'),
                'main_image' => $session->space->main_image,
            ],
            'date_label' => $presentation->dateLabel($session->checked_in_at, $locale),
            // A session still running has no end to show yet.
            'time_label' => $end === null ? null : $presentation->timeLabel($session->checked_in_at, $end, $locale),
            'checked_in_at' => $session->checked_in_at->toIso8601String(),
            'checked_out_at' => $end?->toIso8601String(),
            'duration_seconds' => max(0, ($end ?? now())->getTimestamp() - $session->checked_in_at->getTimestamp()),
            'payment' => app(PaymentStatePresenter::class)->for($session, $request->user()),
            // What was taken off it — offer, coupon, plan discount — as it
            // was when the price was locked (AppliedDiscountPresenter).
            'discounts' => app(AppliedDiscountPresenter::class)->for($session),
        ];
    }
}
