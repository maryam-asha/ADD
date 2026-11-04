<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\LateArrivalBilling;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Services\PlanAllowanceService;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Domain\Pricing\Services\QuoteRecorder;
use App\Domain\Pricing\ValueObjects\PriceQuote;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SessionClosureService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
        private readonly PointConversionService $points,
        private readonly PlanAllowanceService $allowances,
        private readonly PriceQuoteService $quotes,
        private readonly QuoteRecorder $recorder,
        private readonly CouponLedger $couponLedger,
        private readonly CoworkingBookingPolicy $policy,
        private readonly BookingPlanCoverage $bookingCoverage,
        private readonly AmountCalculator $amounts,
        private readonly ClosedBookingSettlement $settlement,
    ) {}

    /**
     * Reception enters a specific checkout time (decision #10). Computed
     * from the ACTUAL checked-in-to-checked-out duration, not the booking's
     * originally planned window — early departure or overrun is billed
     * correctly for both entity types uniformly.
     *
     * Locks and re-reads the row first, like autoClose(): the desk checkout,
     * a member-confirmed end (SessionEndService::confirm(), which already
     * holds this lock) and the overdue job can all reach the same session,
     * and $session may be stale by then — deciding on its in-memory state
     * would close it twice and spend the plan hours twice. $session is
     * refreshed afterwards, so callers that read the result off it (the desk
     * controllers audit amount_owed) still see it.
     */
    public function closeOut(Booking|WalkinSession $session, CarbonInterface $enteredTime): void
    {
        DB::transaction(function () use ($session, $enteredTime) {
            // A session priced now may take a limited offer: those rows
            // first, as every pricing transaction takes them (CouponLedger).
            $this->couponLedger->lockForPricing(null);
            $locked = $session::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            $this->assertOpenForClosure($locked);

            if ($enteredTime->lt($locked->checked_in_at)) {
                throw new ReceptionActionException('api.reception.checkout_before_checkin');
            }

            $branch = $locked->space->building->branch;
            $closingTime = $this->closingTimeFor($branch, $enteredTime);

            if ($closingTime === null || $this->localTimeOfDay($enteredTime) > $closingTime) {
                throw new ReceptionActionException('api.reception.checkout_past_closing');
            }

            $this->finalizeClosure($locked, $enteredTime, TerminationSource::Reception);
        });

        $session->refresh();
    }

    /**
     * Identical effect to closeOut(), termination_source = auto, no
     * operator. Called by the scheduled command for any session still
     * checked in past its branch's closing time.
     *
     * $session may be stale: the command loads a page of candidates via
     * chunkById() and can reach this call some time after that read, during
     * which a concurrent manual closeOut() may have already closed the same
     * row. Checking $session's in-memory state would miss that entirely, so
     * this locks and re-reads the row from the database before deciding —
     * the same lock-then-check pattern WalkInCapacityService::start() uses
     * for the identical class of race.
     *
     * The early-walk-in hand-over closes through here too, with its own
     * $source.
     */
    public function autoClose(Booking|WalkinSession $session, CarbonInterface $closingInstant, TerminationSource $source = TerminationSource::Auto): void
    {
        DB::transaction(function () use ($session, $closingInstant, $source) {
            $this->couponLedger->lockForPricing(null);
            $locked = $session::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            $this->assertOpenForClosure($locked);

            $this->finalizeClosure($locked, $closingInstant, $source);
        });
    }

    /**
     * Reception collected cash for exactly what is owed
     * and confirms it here. The money goes through the ledger like every
     * other payment (PaymentService::settleAtReception). The audit entry is
     * the caller's, via LogsSensitiveActions.
     */
    public function settlePayment(Booking|WalkinSession $session, PaymentMethod $method, User $operator): void
    {
        if ($session->checked_out_at === null || $session->amount_owed === null) {
            throw new ReceptionActionException('api.reception.not_yet_checked_out');
        }

        if ($session->payment_state === PaymentState::Paid) {
            throw new ReceptionActionException('api.reception.already_paid', 409);
        }

        // Nothing charged (a free space): nothing to put through the ledger.
        if (bccomp((string) $session->amount_owed, '0', 2) <= 0) {
            $session->forceFill([
                'payment_state' => PaymentState::Paid,
                'payment_method' => $method,
                'paid_by' => $operator->id,
                'paid_at' => now(),
            ])->save();

            return;
        }

        // Exactly what is owed, through the one settlement path: the money
        // becomes points in the member's wallet and is spent on the session,
        // leaving a ledger credit, a debit and a payment row.
        app(PaymentService::class)->settleAtReception(
            $session instanceof Booking ? PaymentPurpose::Booking : PaymentPurpose::CoworkingSession,
            $session,
            $method,
            null,
            null,
            $operator,
        );

        $session->refresh();
    }

    /**
     * Last period's close_time (H:i) for the branch's local calendar date
     * matching $instant, or null if closed that day. Public: Task 7's
     * auto-closure command needs this to decide which open sessions are
     * actually overdue.
     */
    public function closingTimeFor(Branch $branch, CarbonInterface $instant): ?string
    {
        $localInstant = $instant->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'));
        $periods = $this->businessHours->periodsFor($localInstant, $branch);

        if ($periods === []) {
            return null;
        }

        return Collection::make($periods)->pluck('close_time')->sort()->last();
    }

    private function assertOpenForClosure(Booking|WalkinSession $session): void
    {
        if ($session->checked_in_at === null) {
            throw new ReceptionActionException('api.reception.not_checked_in');
        }

        if ($session->checked_out_at !== null) {
            throw new ReceptionActionException('api.reception.already_checked_out', 409);
        }
    }

    /**
     * What closing an open session at `$at` would charge, without closing it
     * or spending any plan hours: the price finalizeClosure() writes, from
     * the same pricing. A booking already paid keeps the price it was paid
     * at, and owes only what it stays past its end (overstayPoints()).
     *
     * @return array{points: int, covered_seconds: int, already_paid: bool}
     */
    public function quote(Booking|WalkinSession $session, CarbonInterface $at): array
    {
        if ($session instanceof Booking && ! $session->isCoworking()) {
            return ['points' => $this->bookedPricePoints($session), 'covered_seconds' => 0, 'already_paid' => $session->payment_state === PaymentState::Paid];
        }

        if ($session instanceof Booking && $session->payment_state === PaymentState::Paid) {
            $overstay = $this->overstayPoints($session, $at);

            return $overstay > 0
                ? ['points' => $overstay, 'covered_seconds' => 0, 'already_paid' => false]
                : ['points' => (int) $session->price_points, 'covered_seconds' => 0, 'already_paid' => true];
        }

        $covered = match (true) {
            $session instanceof WalkinSession => $session->membership !== null ? $this->coverableBlocks($session, $session->membership, $at) : 0,
            default => $this->bookingCoverableBlocks($session, $at),
        };

        // The same price finalizeClosure() bills, from the same quote.
        $points = $this->priceFor($session, $at, $covered)->finalPoints;

        return ['points' => $points, 'covered_seconds' => $covered, 'already_paid' => false];
    }

    /**
     * What a session is charged: the time the plan did not absorb, rounded UP
     * to whole billing blocks — `coworking.billing_increment_minutes`, 15 by
     * default — so the desk never bills an odd 47 minutes: 0–15 bills 15,
     * 15–30 bills 30, 30–45 bills 45, 45–60 bills the hour (product owner,
     * 2026-10-04). A block of 0 or 1 minute bills the time as it was.
     *
     * Priced from check-in over the rounded length, rather than pricing the
     * whole thing and subtracting, so AmountCalculator's documented
     * ceil-to-whole-points rounding (Invariant 2) is applied once, to the
     * real charge.
     */
    private function priceFor(Booking|WalkinSession $session, CarbonInterface $checkedOutAt, int $coveredSeconds): PriceQuote
    {
        $from = $this->billingStartFor($session);
        $billable = (int) $from->diffInSeconds($checkedOutAt) - $coveredSeconds;

        $block = $this->blockSeconds();
        $billed = $billable > 0 ? (int) (ceil($billable / $block) * $block) : 0;

        // The billed stretch, from its start: the record's locked offer or
        // plan discount — or the best offer now — and its coupon terms
        // (PriceQuoteService, the one place a price is composed).
        return $this->quotes->forSession($session, $from, $from->copy()->addSeconds($billed));
    }

    /**
     * What a paid booking owes for staying past its end: the time after
     * end_at, rounded up to whole billing blocks like any session, at the
     * booking's own rate and terms. Coworking only — rooms, offices and
     * halls keep their existing rules. Nothing when it closes at or before
     * its end: leaving early is never refunded.
     */
    private function overstayPoints(Booking $booking, CarbonInterface $checkedOutAt, bool $spendPlanHours = false): int
    {
        $over = (int) $booking->end_at->diffInSeconds($checkedOutAt, false);

        if ($over <= 0 || $booking->space->space_type !== SpaceType::CoSpace) {
            return 0;
        }

        $block = $this->blockSeconds();

        // Plan hours left cover the overstay first, as for any session.
        $covered = $this->bookingCoverage->coverOverstay($booking, $booking->end_at, $checkedOutAt, $block, $spendPlanHours);
        $billable = $over - $covered;

        if ($billable <= 0) {
            return 0;
        }

        $billed = (int) (ceil($billable / $block) * $block);

        return $this->quotes->forSession($booking, $booking->end_at, $booking->end_at->copy()->addSeconds($billed))->finalPoints;
    }

    /**
     * Where the bill starts: the check-in — except an unpaid booking whose
     * member came late, billed from the booking's start when
     * coworking.late_arrival_billing says so (CoworkingBookingPolicy). A
     * paid booking keeps the price it was paid at either way.
     */
    private function billingStartFor(Booking|WalkinSession $session): CarbonInterface
    {
        if ($session instanceof Booking
            && $session->payment_state === PaymentState::Unpaid
            && $session->pointsPaid() === 0
            && $this->policy->lateArrivalBilling() === LateArrivalBilling::BookingStart) {
            return $session->start_at->min($session->checked_in_at);
        }

        return $session->checked_in_at;
    }

    /** The billing block, in seconds: `coworking.billing_increment_minutes`, 15 by default. */
    private function blockSeconds(): int
    {
        return 60 * max(1, (int) $this->settings->get('coworking.billing_increment_minutes', 15));
    }

    /**
     * What the plan's hours cover of the session, in the same whole blocks
     * the rest is billed in: its time in the plan's window rounded up, never
     * more than the session's own rounded length, and spent from the quota
     * only in whole blocks. So 47 minutes, 30 of them in the window, is two
     * quarters from the plan and two billed; 47 all in the window is a
     * whole hour from the plan.
     */
    private function coverableBlocks(WalkinSession $session, Membership $membership, CarbonInterface $checkedOutAt): int
    {
        $block = $this->blockSeconds();
        $sessionLength = (int) (ceil(max(0, (int) $session->checked_in_at->diffInSeconds($checkedOutAt)) / $block) * $block);

        return min($sessionLength, $this->allowances->coverableSeconds($membership, $session->user, $session->space, $session->checked_in_at, $checkedOutAt, $block));
    }

    /** An unpaid booking's plan cover at closure, spending nothing (the quote): never more than its rounded length. */
    private function bookingCoverableBlocks(Booking $booking, CarbonInterface $at): int
    {
        $from = $this->billingStartFor($booking);

        return min($this->roundedLength($from, $at), $this->bookingCoverage->coverableFor($booking, $from, $at, $this->blockSeconds()));
    }

    private function roundedLength(CarbonInterface $from, CarbonInterface $to): int
    {
        $block = $this->blockSeconds();

        return (int) (ceil(max(0, (int) $from->diffInSeconds($to)) / $block) * $block);
    }

    private function finalizeClosure(Booking|WalkinSession $session, CarbonInterface $checkedOutAt, TerminationSource $source): void
    {
        if ($session instanceof Booking && ! $session->isCoworking()) {
            $this->finalizeRoomClosure($session, $checkedOutAt, $source);

            return;
        }

        $coveredSeconds = $this->consumePlanCoverage($session, $checkedOutAt);

        // `amount_owed`/`currency` are the session's money-denominated record
        // of the charge and remain decimal USD — only the wallet ledger became
        // point-denominated. Converting the point charge back to USD here is
        // the intended direction, not leftover scaffolding.
        $quote = $this->priceFor($session, $checkedOutAt, $coveredSeconds);
        $points = $quote->finalPoints;

        $amount = $this->points->pointsToUsd($points);

        $attributes = [
            // Normalize to UTC: Eloquent's datetime cast stores the Carbon value's
            // current wall-clock as-is and reinterprets it as UTC on read, so
            // non-UTC inputs (e.g. from autoClose()'s Damascus-timezone closing time)
            // would round-trip with a 3-hour drift. This ensures correct storage
            // regardless of the caller's timezone.
            'checked_out_at' => $checkedOutAt->copy()->setTimezone('UTC'),
            'termination_source' => $source,
            'amount_owed' => $amount,
            'currency' => 'USD',
        ];

        // The same price in points, what a payment settles. A walk-in is
        // priced only now; a booking keeps the price it was made (and
        // perhaps paid) at, unless it closes unpaid and is repriced here.
        $repriced = $session instanceof WalkinSession || $session->payment_state !== PaymentState::Paid;

        if ($session instanceof WalkinSession) {
            $attributes += $quote->attributes();
        } elseif ($repriced) {
            $attributes += $this->repriceUnpaidBooking($session, $points) + ['list_price_points' => $quote->listPoints];

            // The plan covered all of it: nothing is owed.
            if ($points === 0 && $coveredSeconds > 0) {
                $attributes += ['payment_state' => PaymentState::Paid, 'payment_source' => PaymentSource::Plan, 'paid_at' => now()];
            }
        } elseif (($overstay = $this->overstayPoints($session, $checkedOutAt, true)) > 0) {
            // Paid for its own window; the time past it is owed on top.
            $price = (int) $session->price_points + $overstay;
            $attributes['price_points'] = $price;
            $attributes['payment_state'] = PaymentState::Unpaid;
            $attributes['amount_owed'] = $this->points->pointsToUsd($price);
        }

        // Checked out is the booking's outcome: it happened
        // (docs/decisions/booking-outcomes-and-extension.md). Still payable
        // while unpaid, and still holding its slot to end_at.
        if ($session instanceof Booking) {
            $attributes['status'] = BookingStatus::Completed;
        }

        if ($session instanceof WalkinSession && $coveredSeconds > 0) {
            $attributes['plan_covered_seconds'] = $coveredSeconds;

            // Only a session the plan covered in FULL is settled here. A
            // partly covered one still owes money and stays Unpaid, to be
            // collected through settlePayment() like any other walk-in.
            if (bccomp((string) $amount, '0.00', 2) <= 0) {
                $attributes['payment_state'] = PaymentState::Paid;
                $attributes['payment_source'] = PaymentSource::Plan;
                $attributes['paid_at'] = now();
            }
        }

        $session->forceFill($attributes)->save();

        if ($repriced) {
            $this->recorder->reprice($quote, $session, $session->user, $session instanceof Booking ? PricingPurpose::Booking : PricingPurpose::CoworkingSession);
        }

        $this->supersedePendingEnd($session, $attributes['checked_out_at']);

        if ($session instanceof Booking) {
            $this->settlement->settle($session);
        }
    }

    /**
     * A room, office or hall is reserved for its whole booking, so it costs
     * its booked price however long it was used: no repricing for arriving
     * late or leaving early, no overstay, and the plan hours taken when it
     * was made stand (product owner, 2026-11-03). Coworking bookings are
     * billed for their time, above.
     */
    private function finalizeRoomClosure(Booking $booking, CarbonInterface $checkedOutAt, TerminationSource $source): void
    {
        $price = $this->bookedPricePoints($booking);
        $paid = $booking->pointsPaid();

        $attributes = [
            'checked_out_at' => $checkedOutAt->copy()->setTimezone('UTC'),
            'termination_source' => $source,
            'price_points' => $price,
            'amount_owed' => $this->points->pointsToUsd($price),
            'currency' => 'USD',
            'status' => BookingStatus::Completed,
        ];

        if ($booking->payment_state !== PaymentState::Paid && $paid > 0 && $paid >= $price) {
            $attributes['payment_state'] = PaymentState::Paid;
        }

        $booking->forceFill($attributes)->save();

        $this->supersedePendingEnd($booking, $attributes['checked_out_at']);

        $this->settlement->settle($booking);
    }

    /** Rows made before `price_points` existed cost their booked window, as the booking fulfiller prices them. */
    private function bookedPricePoints(Booking $booking): int
    {
        return $booking->price_points ?? $this->amounts->forRange($booking->space, $booking->start_at, $booking->end_at);
    }

    /**
     * An end request still waiting on this session is moot once it is
     * closed — at the desk, by auto-close, or by a confirmation that
     * already marked its own request before calling here.
     */
    private function supersedePendingEnd(Booking|WalkinSession $session, CarbonInterface $checkedOutAt): void
    {
        $session->pendingEndRequest()->update([
            'status' => SessionEndRequestStatus::Superseded->value,
            'resolved_at' => now(),
            'checked_out_at' => $checkedOutAt,
        ]);
    }

    /**
     * An unpaid booking is billed for the time it was used. Part of it may
     * already be paid — a paid booking that was then extended — and what
     * it owes is its price less those payments (Booking::pointsPaid), so
     * nothing already paid is billed again. It is never repriced below what
     * was paid (no refund for leaving early, as for any paid booking), and
     * once what was paid covers the time used it is paid.
     *
     * @return array<string, mixed>
     */
    private function repriceUnpaidBooking(Booking $booking, int $points): array
    {
        $paid = $booking->pointsPaid();

        if ($paid <= 0) {
            return ['price_points' => $points];
        }

        $attributes = ['price_points' => max($points, $paid)];

        if ($paid >= $points) {
            $attributes['payment_state'] = PaymentState::Paid;
        }

        return $attributes;
    }

    /**
     * Spends whatever of this session falls inside the member's plan access
     * window against their remaining hour quota, and returns the seconds
     * actually absorbed.
     *
     * Bookings are excluded on purpose: they already have their own plan
     * benefit in PlanDiscountService, applied at creation, and stacking the
     * two would discount the same hour twice.
     */
    private function consumePlanCoverage(Booking|WalkinSession $session, CarbonInterface $checkedOutAt): int
    {
        // An unpaid booking is billed for the stretch it used, so its plan
        // hours are settled on that stretch; a paid one keeps what it took
        // (its overstay is covered in overstayPoints()).
        if ($session instanceof Booking) {
            return $session->payment_state === PaymentState::Paid
                ? 0
                : min(
                    $this->roundedLength($this->billingStartFor($session), $checkedOutAt),
                    $this->bookingCoverage->settle($session, $this->billingStartFor($session), $checkedOutAt, $this->blockSeconds()),
                );
        }

        if ($session->membership_id === null) {
            return 0;
        }

        $membership = $session->membership;

        if ($membership === null) {
            return 0;
        }

        $coverable = $this->coverableBlocks($session, $membership, $checkedOutAt);

        if ($coverable <= 0) {
            return 0;
        }

        $allowance = $this->allowances->allowanceFor($membership, $session->space->space_type, $session->space_id);

        if ($allowance === null) {
            return 0;
        }

        $this->allowances->record($membership, $session->user, $allowance, $session, $coverable, $session->checked_in_at);

        return $coverable;
    }

    private function localTimeOfDay(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'))->format('H:i');
    }
}
