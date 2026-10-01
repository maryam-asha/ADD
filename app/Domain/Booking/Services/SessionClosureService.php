<?php

namespace App\Domain\Booking\Services;

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
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SessionClosureService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
        private readonly PointConversionService $points,
        private readonly PlanHourCoverageService $planHours,
        private readonly PlanDiscountService $discounts,
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
     */
    public function autoClose(Booking|WalkinSession $session, CarbonInterface $closingInstant): void
    {
        DB::transaction(function () use ($session, $closingInstant) {
            $locked = $session::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            $this->assertOpenForClosure($locked);

            $this->finalizeClosure($locked, $closingInstant, TerminationSource::Auto);
        });
    }

    /**
     * Reception collected cash/sham/mtn/syriatel for exactly what is owed
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

    private function finalizeClosure(Booking|WalkinSession $session, CarbonInterface $checkedOutAt, TerminationSource $source): void
    {
        $coveredSeconds = $this->consumePlanCoverage($session, $checkedOutAt);

        // `amount_owed`/`currency` are the session's money-denominated record
        // of the charge and remain decimal USD — only the wallet ledger became
        // point-denominated. Converting the point charge back to USD here is
        // the intended direction, not leftover scaffolding.
        $points = $this->amounts->forRange(
            $session->space,
            $session->checked_in_at,
            // Bill only what the plan did not absorb. Shortening the range by
            // the covered seconds, rather than pricing the whole thing and
            // subtracting, keeps AmountCalculator's documented ceil-to-whole-
            // points rounding (Invariant 2) applied once to the real charge.
            $coveredSeconds > 0 ? $checkedOutAt->copy()->subSeconds($coveredSeconds) : $checkedOutAt,
        );

        if ($session instanceof Booking) {
            $points = $this->withBookingDiscount($session, $points);
        }

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
        if ($session instanceof WalkinSession) {
            $attributes['price_points'] = $points;
        } elseif ($session->payment_state !== PaymentState::Paid) {
            $attributes += $this->repriceUnpaidBooking($session, $points);
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

        // An end request still waiting on this session is moot once it is
        // closed — at the desk, by auto-close, or by a confirmation that
        // already marked its own request before calling here.
        $session->pendingEndRequest()->update([
            'status' => SessionEndRequestStatus::Superseded->value,
            'resolved_at' => now(),
            'checked_out_at' => $attributes['checked_out_at'],
        ]);
    }

    /**
     * A booking keeps the plan discount it was made with
     * (PlanDiscountRedemption) when it is priced at check-out: the price it
     * was quoted is the price, whenever it is paid. Read from the booking's
     * own redemption, never re-resolved, like the booking's detail.
     */
    private function withBookingDiscount(Booking $booking, int $points): int
    {
        $discount = PlanDiscountRedemption::query()->where('booking_id', $booking->id)->first()?->discount;

        return $discount === null ? $points : $this->discounts->apply($points, $discount);
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
        if (! $session instanceof WalkinSession || $session->membership_id === null) {
            return 0;
        }

        $membership = $session->membership;

        if ($membership === null) {
            return 0;
        }

        $coverable = $this->planHours->coverableSeconds($membership, $session->checked_in_at, $checkedOutAt);

        if ($coverable <= 0) {
            return 0;
        }

        return $this->planHours->consume($membership, $session, $coverable);
    }

    private function localTimeOfDay(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'))->format('H:i');
    }
}
