<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;
use App\Support\Presenters\AvailabilityPresenter;
use App\Support\Presenters\PaymentStatePresenter;
use App\Support\Presenters\PriceLabelPresenter;
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
        private readonly BookingExtensionPlanner $extensions,
        private readonly PriceLabelPresenter $prices,
        private readonly BookingRefunder $refunder,
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

        // Auto-confirmed: no approval was ever required or recorded. A
        // completed booking or a no-show went through the same door.
        $wasConfirmed = in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Completed, BookingStatus::NoShow], true);

        if ($wasConfirmed && $booking->approved_at === null) {
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
            BookingStatus::Confirmed, BookingStatus::Completed, BookingStatus::NoShow => ['decision', 'approved', 'complete'],
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
            // A lapsed request stores no reason; it is explained in the
            // viewer's language rather than the server's at the time.
            'rejection_reason' => $booking->lapsedUnreviewed()
                ? __('api.booking.lapsed_unreviewed', [], $locale)
                : $booking->rejection_reason,
        ];
    }

    /**
     * One line under the status, only where two bookings with the same
     * status differ in what happened to the money
     * (docs/decisions/booking-outcomes-and-extension.md): a no-show that was
     * paid keeps its charge while an unpaid one owes nothing, a completed one
     * may still be owed, and a closed one that was paid was refunded.
     * Everything else has nothing to add: null.
     */
    public function statusNote(Booking $booking, string $locale): ?string
    {
        $paid = $booking->payment_state === PaymentState::Paid;

        $key = match ($booking->status) {
            BookingStatus::NoShow => $paid ? 'no_show_charged' : 'no_show_free',
            BookingStatus::Completed => ! $paid && (int) $booking->price_points > 0 ? 'payment_due' : null,
            BookingStatus::Cancelled, BookingStatus::Rejected => $paid && $this->wasRefunded($booking) ? 'refunded' : null,
            default => null,
        };

        return $key === null ? null : __("api.booking.status_notes.{$key}", [], $locale);
    }

    /**
     * BookingRefunder's two paths, read back: the payment rows that paid it
     * were refunded, or — paid before rows existed — it was paid from the
     * member's wallet, which that path always credits back.
     */
    private function wasRefunded(Booking $booking): bool
    {
        $rows = $booking->payments;

        return $rows->isEmpty()
            ? $booking->payment_source === PaymentSource::Wallet
            : $rows->contains(fn ($payment) => $payment->isRefunded());
    }

    /**
     * What the member may do with this booking, and why not when they may
     * not.
     *
     * `can_cancel` delegates to BookingCancellationService::canCancel() —
     * the same predicate cancel() enforces — so the button is never shown
     * for an action that would come straight back as a 422. `can_extend`
     * is BookingExtensionPlanner's, which the extend POST re-checks.
     *
     * @return array<string, mixed>
     */
    public function actions(Booking $booking, string $locale): array
    {
        $canCancel = $this->cancellations->canCancel($booking);
        $windowMinutes = $this->cancellations->windowMinutesFor($booking);
        $extension = $this->extensions->options($booking);

        return [
            'can_cancel' => $canCancel,
            'cancel_deadline_at' => $this->time->toLocal($this->cancellations->deadlineFor($booking))?->toIso8601String(),
            'cancel_note' => __('api.booking.cancellation_note', [
                'duration' => $this->presenter->durationLabel($windowMinutes),
            ], $locale),
            'can_extend' => $extension->canExtend(),
            'extend_disabled_reason' => $extension->reason?->label($locale),
        ];
    }

    /**
     * GET .../extension-options: each length the booking can grow by, with
     * the time it would end and what it costs — or why it cannot grow
     * (docs/decisions/booking-outcomes-and-extension.md). `latest_end_at` is
     * the latest the member can stay.
     *
     * @return array<string, mixed>
     */
    public function extensionOptions(Booking $booking, string $locale, ?User $viewer): array
    {
        $plan = $this->extensions->options($booking);

        return [
            'can_extend' => $plan->canExtend(),
            'reason' => $plan->reason?->value,
            'reason_label' => $plan->reason?->label($locale),
            'current_end_at' => $this->time->toLocal($plan->currentEnd)?->toIso8601String(),
            'latest_end_at' => $this->time->toLocal($plan->latestEnd)?->toIso8601String(),
            'options' => array_map(function (array $option) use ($viewer, $locale) {
                $localEnd = $this->time->toLocal($option['end_at']);
                $labels = $option['points'] > 0
                    ? $this->prices->total($option['points'], $viewer, $locale)
                    : ['price' => null, 'price_point' => null];

                return [
                    'minutes' => $option['minutes'],
                    'label' => $this->presenter->durationLabel($option['minutes']),
                    'end_at' => $localEnd?->toIso8601String(),
                    'end_time' => $localEnd?->format('h:i A'),
                    'price_points' => $option['points'],
                    'price' => $labels['price'],
                    'price_point' => $labels['price_point'],
                ];
            }, $plan->options),
        ];
    }

    /**
     * The two fields of the payment block (PaymentStatePresenter) the detail
     * keeps beside `payment_state`: the latest payment, for the app to poll,
     * and whether a card payment for the booking is still in flight.
     *
     * @return array{payment_id: int|null, awaiting_payment: bool}
     */
    public function paymentTracking(Booking $booking): array
    {
        $block = app(PaymentStatePresenter::class)->for($booking, $booking->user);

        return [
            'payment_id' => $block['payment_id'],
            'awaiting_payment' => $block['state'] === 'pending',
        ];
    }

    /**
     * The cancel screen: until when the booking can be cancelled, what
     * cancelling gives back and to which wallet. The amount is
     * BookingRefunder's own quote — the same rows and arithmetic the refund
     * runs — so what the screen promises is what comes back. Null when the
     * booking cannot be cancelled.
     *
     * @return array{deadline_label: string, refund_points: int, refund_price: string|null, refund_to: string|null}|null
     */
    public function cancellation(Booking $booking, string $locale, ?User $viewer): ?array
    {
        if (! $this->cancellations->canCancel($booking)) {
            return null;
        }

        $deadline = $this->time->toLocal($this->cancellations->deadlineFor($booking));
        $quote = $this->refunder->quote($booking);
        $points = $quote['points'];

        return [
            'deadline_label' => __('api.booking.cancel_until', [
                'at' => $deadline->copy()->locale($locale)->translatedFormat($locale === 'ar' ? 'j F Y' : 'F j, Y')
                    .($locale === 'ar' ? '، ' : ', ')
                    .$deadline->format('h:i A'),
            ], $locale),
            'refund_points' => $points,
            'refund_price' => $points > 0 ? $this->prices->total($points, $viewer, $locale)['price'] : null,
            'refund_to' => $points > 0
                ? implode($locale === 'ar' ? '، ' : ', ', array_map(fn (Wallet $wallet) => $this->walletLabel($wallet, $locale), $quote['wallets']))
                : null,
        ];
    }

    /** "Your wallet", or the company whose wallet paid. */
    private function walletLabel(Wallet $wallet, string $locale): string
    {
        return $wallet->owner_type === OwnerType::Company
            ? (Company::query()->whereKey($wallet->owner_id)->value('legal_name') ?? __('api.booking.refund_to_company_wallet', [], $locale))
            : __('api.booking.refund_to_own_wallet', [], $locale);
    }

    /**
     * The list card's day line: "Today, August 17, 2026", "Tomorrow, …", or
     * just the date — in the district's calendar, not the server's, and with
     * the month named in the viewer's language.
     */
    public function dateLabel(CarbonInterface $start, string $locale): string
    {
        $local = $this->time->toLocal($start);
        $today = $this->time->toLocal(now());
        $date = $local->copy()->locale($locale)->translatedFormat($locale === 'ar' ? 'j F Y' : 'F j, Y');

        return match ($local->toDateString()) {
            $today->toDateString() => __('api.booking.date_today', ['date' => $date], $locale),
            $today->copy()->addDay()->toDateString() => __('api.booking.date_tomorrow', ['date' => $date], $locale),
            default => $date,
        };
    }

    /** The list card's time line: "01:00 PM - 02:00 PM (1h)". */
    public function timeLabel(CarbonInterface $start, CarbonInterface $end, string $locale): string
    {
        $localStart = $this->time->toLocal($start);
        $localEnd = $this->time->toLocal($end);

        return __('api.booking.time_range', [
            'start' => $localStart->format('h:i A'),
            'end' => $localEnd->format('h:i A'),
            'duration' => $this->presenter->durationLabel((int) $localStart->diffInMinutes($localEnd)),
        ], $locale);
    }

    /**
     * Where the member heads on arrival: the space they sit in and its
     * floor — the lent room a co-working booking was placed in, else the
     * booked space itself (docs/decisions/coworking-seat-pool.md,
     * "Seating placement").
     *
     * @return array{space_id: int, title: string, building: ?string, floor: ?string, zone: ?string, formatted: string}
     */
    public function seat(Space $space, string $locale): array
    {
        $space->loadMissing(['building', 'zone.floor']);

        $title = $space->displayTitle($locale);
        $floor = $space->zone?->floor?->label;

        return [
            'space_id' => $space->id,
            'title' => $title,
            'building' => $space->building?->translate('name', $locale),
            'floor' => $floor,
            'zone' => $space->zone?->label,
            'formatted' => collect([$title, $floor])->filter()->implode(' – '),
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
