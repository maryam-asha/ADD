<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;
use App\Support\Presenters\AvailabilityPresenter;
use App\Support\Presenters\PaymentStatePresenter;
use App\Support\Time\LocalTime;
use Carbon\CarbonInterface;

/**
 * Builds the three composite blocks the booking screens render: the price
 * breakdown, the payment-method choices, and the request-status stepper.
 *
 * This lives in Domain rather than in a Resource because the stepper is a
 * state machine over `status`/`approved_at` — domain knowledge, not
 * formatting — and because the admin dashboard needs the same breakdown.
 */
class BookingPresentationService
{
    public function __construct(
        private readonly AmountCalculator $amounts,
        private readonly PointConversionService $points,
        private readonly WalletService $wallets,
        private readonly SettingService $settings,
        private readonly BookingCancellationService $cancellations,
        private readonly LocalTime $time,
        private readonly AvailabilityPresenter $presenter,
        private readonly MembershipResolver $membershipResolver,
        private readonly PlanDiscountService $discounts,
    ) {}

    /**
     * The "1 hour × $12.00 / Subtotal / VAT (0%) / Total" block.
     *
     * VAT reads from a setting rather than a literal zero, so the zero is
     * a recorded decision and the arithmetic already handles a real rate
     * if one is ever introduced.
     *
     * Two distinct discount modes, never both: pass `$booking` for an
     * already-created booking's detail, which shows the discount it
     * actually got — read from its PlanDiscountRedemption row, historical
     * and never re-resolved, so it can't disagree with what the member was
     * actually charged even if the discount has since lapsed or been
     * exhausted. Pass `$member` for a live pre-booking preview, which shows
     * the discount they would get if they booked right now
     * (docs/decisions/plan-discounts-design.md).
     *
     * @return array<string, mixed>
     */
    public function breakdown(Space $space, CarbonInterface $start, CarbonInterface $end, string $locale, ?Booking $booking = null, ?User $member = null): array
    {
        $rawPoints = $this->amounts->forRange($space, $start, $end);
        $discountPoints = 0;
        $discountPercentage = 0;

        if ($booking !== null) {
            $redemption = PlanDiscountRedemption::where('booking_id', $booking->id)->first();

            if ($redemption !== null) {
                $discountPoints = $redemption->points_discounted;
                $discountPercentage = $redemption->discount->percentage;
            }
        } elseif ($member !== null) {
            $membership = $this->membershipResolver->activeFor($member);
            $discount = $membership !== null ? $this->discounts->resolveFor($membership, $space) : null;

            if ($discount !== null) {
                $finalPoints = $this->discounts->apply($rawPoints, $discount);
                $discountPoints = $rawPoints - $finalPoints;
                $discountPercentage = $discount->percentage;
            }
        }

        $points = $rawPoints - $discountPoints;
        $vatPercent = (int) $this->settings->get('booking.vat_percent', 0);

        // Integer arithmetic, rounding the VAT up, so a partial point is
        // never charged as less than a whole one — the same direction
        // AmountCalculator rounds (PointRoundingFavoursTheHouseTest). VAT
        // is computed off the post-discount amount, matching the order the
        // creation services actually charge in.
        $vatPoints = $vatPercent > 0 ? (int) ceil($points * $vatPercent / 100) : 0;
        $totalPoints = $points + $vatPoints;

        $minutes = (int) $start->diffInMinutes($end);
        $rate = (int) ($space->hourly_rate_points ?? 0);

        $lines = [
            [
                'key' => 'base',
                'label' => __('api.booking.hours_times_rate', [
                    'duration' => $this->presenter->durationLabel($minutes),
                    'rate' => $this->money($rate),
                ], $locale),
                'points' => $rawPoints,
                'amount' => $this->points->pointsToUsd($rawPoints),
                'formatted' => $this->money($rawPoints),
            ],
        ];

        if ($discountPoints > 0) {
            $lines[] = [
                'key' => 'discount',
                'label' => __('api.booking.discount_label', ['percent' => $discountPercentage], $locale),
                'points' => -$discountPoints,
                'amount' => -$this->points->pointsToUsd($discountPoints),
                'formatted' => '-'.$this->money($discountPoints),
            ];
        }

        return [
            'lines' => $lines,
            'subtotal' => [
                'points' => $points,
                'amount' => $this->points->pointsToUsd($points),
                'formatted' => $this->money($points),
            ],
            'vat' => [
                'rate_percent' => $vatPercent,
                'label' => __('api.booking.vat_label', ['percent' => $vatPercent], $locale),
                'points' => $vatPoints,
                'amount' => $this->points->pointsToUsd($vatPoints),
                'formatted' => $this->money($vatPoints),
            ],
            'total' => [
                'points' => $totalPoints,
                'amount' => $this->points->pointsToUsd($totalPoints),
                'formatted' => $this->money($totalPoints),
            ],
            'currency' => 'USD',
            'currency_symbol' => '$',
        ];
    }

    /**
     * Every wallet the member may spend from, plus cash at reception.
     *
     * A wallet that cannot cover the booking is returned with
     * `sufficient: false` rather than omitted — the member should see the
     * company wallet exists and is short, not wonder where it went.
     *
     * "Cash at arrival" is synthetic: it is what PaymentState::Unpaid plus
     * reception settlement already means, so it needs no enum case and no
     * column.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentMethods(User $member, int $totalPoints, string $locale): array
    {
        $options = $this->wallets->spendOptions($member, WalletTransactionCategory::SpaceSpecific);

        $wallets = [];
        $selected = false;

        foreach ($options as $option) {
            $sufficient = $option['usable_balance'] >= $totalPoints;

            // Pre-select the first wallet that can actually pay.
            $isSelected = $sufficient && ! $selected;
            $selected = $selected || $isSelected;

            $wallets[] = [
                'key' => 'wallet',
                'owner_type' => $option['owner_type'],
                'owner_id' => $option['owner_id'],
                'owner_label' => $option['owner_label'],
                'label' => __('api.booking.payment_methods.wallet', [], $locale),
                'usable_balance' => $option['usable_balance'],
                'usable_balance_formatted' => __('api.booking.points_amount', [
                    'points' => $option['usable_balance'],
                ], $locale),
                'sufficient' => $sufficient,
                'selected' => $isSelected,
            ];
        }

        $wallets[] = [
            'key' => 'cash',
            'owner_type' => null,
            'owner_id' => null,
            'owner_label' => null,
            'label' => __('api.booking.payment_methods.cash', [], $locale),
            'usable_balance' => null,
            'usable_balance_formatted' => null,
            'sufficient' => true,
            // Cash is the fallback selection when no wallet can cover it.
            'selected' => ! $selected,
        ];

        return $wallets;
    }

    /**
     * The Submitted → Under Review → Approved/Rejected stepper.
     *
     * Derived from `status` and `approved_at`, never stored. A booking on
     * a space that auto-confirms never had a review, so its stepper
     * collapses to a single completed step rather than showing a review
     * stage that never happened.
     *
     * @return array<string, mixed>
     */
    public function requestStatus(Booking $booking, string $locale): array
    {
        $submitted = [
            'key' => 'submitted',
            'label' => __('api.booking.steps.submitted', [], $locale),
            'state' => 'complete',
            'at' => $this->time->toLocal($booking->created_at)?->toIso8601String(),
        ];

        // Auto-confirmed: no approval was ever required or recorded.
        if ($booking->status === BookingStatus::Confirmed && $booking->approved_at === null) {
            return [
                'current' => 'confirmed',
                'steps' => [
                    $submitted,
                    [
                        'key' => 'confirmed',
                        'label' => __('api.booking.steps.confirmed', [], $locale),
                        'state' => 'complete',
                        'at' => $this->time->toLocal($booking->created_at)?->toIso8601String(),
                    ],
                ],
                'rejection_reason' => null,
            ];
        }

        $decidedAt = $this->time->toLocal($booking->approved_at)?->toIso8601String();

        [$current, $decisionKey, $decisionState] = match ($booking->status) {
            BookingStatus::Pending => ['under_review', 'approved', 'upcoming'],
            BookingStatus::Confirmed => ['decision', 'approved', 'complete'],
            BookingStatus::Rejected => ['decision', 'rejected', 'complete'],
            BookingStatus::Cancelled => ['decision', 'cancelled', 'complete'],
        };

        return [
            'current' => $current,
            'steps' => [
                $submitted,
                [
                    'key' => 'under_review',
                    'label' => __('api.booking.steps.under_review', [], $locale),
                    'state' => $booking->status === BookingStatus::Pending ? 'current' : 'complete',
                    'at' => null,
                ],
                [
                    'key' => $decisionKey,
                    'label' => __("api.booking.steps.{$decisionKey}", [], $locale),
                    'state' => $decisionState,
                    'at' => $decisionState === 'complete' ? $decidedAt : null,
                ],
            ],
            'rejection_reason' => $booking->rejection_reason,
        ];
    }

    /**
     * What the member may do with this booking, and why not when they may
     * not.
     *
     * `can_cancel` delegates to BookingCancellationService::canCancel() —
     * the same predicate cancel() enforces — so the button is never shown
     * for an action that would come straight back as a 422.
     *
     * @return array<string, mixed>
     */
    public function actions(Booking $booking, string $locale): array
    {
        $canCancel = $this->cancellations->canCancel($booking);
        $windowMinutes = $this->cancellations->windowMinutesFor($booking);

        // Extension is only meaningful once the member is actually in the
        // room (BookingExtensionService requires checked_in_at).
        $canExtend = $booking->checked_in_at !== null
            && $booking->checked_out_at === null
            && $booking->status === BookingStatus::Confirmed;

        return [
            'can_cancel' => $canCancel,
            'cancel_deadline_at' => $this->time->toLocal($this->cancellations->deadlineFor($booking))?->toIso8601String(),
            'cancel_note' => __('api.booking.cancellation_note', [
                'duration' => $this->presenter->durationLabel($windowMinutes),
            ], $locale),
            'can_extend' => $canExtend,
            'extend_disabled_reason' => $canExtend
                ? null
                : __('api.reception.not_checked_in', [], $locale),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(Booking $booking, string $locale): array
    {
        // The block every payable shows (PaymentStatePresenter), added to the
        // keys this one always had. `state` stays paid/unpaid for the apps
        // already reading it; a card payment in flight is `awaiting_payment`.
        $block = app(PaymentStatePresenter::class)->for($booking, $booking->user);

        return [
            'state' => $booking->payment_state->value,
            'source' => $booking->payment_source?->value,
            'label' => match ($booking->payment_source) {
                PaymentSource::Wallet => __('api.booking.payment_methods.wallet', [], $locale),
                PaymentSource::Cash => __('api.booking.payment_methods.cash', [], $locale),
                PaymentSource::Gateway => __('api.payment.methods.paymera', [], $locale),
                default => $booking->payment_state === PaymentState::Paid
                    ? __('api.booking.payment_methods.wallet', [], $locale)
                    : __('api.booking.payment_methods.cash', [], $locale),
            },
            'method' => $block['method'],
            'amount_points' => $block['amount_points'],
            'price' => $block['price'],
            'price_point' => $block['price_point'],
            'paid_at' => $block['paid_at'],
            'payment_id' => $block['payment_id'],
            'awaiting_payment' => $block['state'] === 'pending',
        ];
    }

    /**
     * The date/time block, in the same dialect as the events calendar.
     *
     * @return array<string, mixed>
     */
    public function when(CarbonInterface $start, CarbonInterface $end): array
    {
        $localStart = $this->time->toLocal($start);
        $localEnd = $this->time->toLocal($end);

        $startLabel = $localStart->format('h:i A');
        $endLabel = $localEnd->format('h:i A');
        $minutes = (int) $localStart->diffInMinutes($localEnd);

        return [
            'date' => $localStart->format('Y-m-d'),
            'date_formatted' => $localStart->format('F j, Y'),
            'weekday' => strtoupper($localStart->format('D')),
            'day' => $localStart->format('d'),
            'month' => strtoupper($localStart->format('M')),
            'start_at' => $localStart->toIso8601String(),
            'end_at' => $localEnd->toIso8601String(),
            'start_time' => $startLabel,
            'end_time' => $endLabel,
            'range' => "{$startLabel} – {$endLabel}",
            'duration_minutes' => $minutes,
            'duration_label' => $this->presenter->durationLabel($minutes),
        ];
    }

    private function money(int $points): string
    {
        return '$'.number_format((float) $this->points->pointsToUsd($points), 2);
    }
}
