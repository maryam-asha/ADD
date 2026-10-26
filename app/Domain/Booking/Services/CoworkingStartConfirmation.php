<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Events\ArrivalRequestResolved;
use App\Domain\Booking\Events\CoworkingStartConfirmed;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\SessionCoverageEvaluator;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Support\Facades\DB;

/**
 * Reception confirming that a member who scanned the kiosk is here: the one
 * place a scanned coworking session starts, and it starts now — the time
 * reception spent getting to it is not billed
 * (docs/decisions/coworking-self-service-sessions.md). Every rule is applied
 * again by the services that own it, since minutes may have passed since the
 * scan: the booking check-in's, or the walk-in's capacity and hours.
 */
class CoworkingStartConfirmation
{
    public function __construct(
        private readonly BookingCheckInService $checkIns,
        private readonly WalkInCapacityService $walkIns,
        private readonly SessionCoverageEvaluator $coverage,
        private readonly RealtimeBroadcaster $realtime,
    ) {}

    public function confirm(ArrivalRequest $request, User $operator): Booking|WalkinSession
    {
        return DB::transaction(function () use ($request, $operator) {
            $locked = ArrivalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ArrivalRequestStatus::Pending) {
                throw new ReceptionActionException('api.kiosk.arrival_request_not_pending', 409);
            }

            $member = $locked->user;

            // Serialized with the member's own scans (CoworkingSessionService::start()).
            User::query()->whereKey($member->id)->lockForUpdate()->first();

            if ($locked->space === null || $locked->space->status !== OperationalStatus::Active) {
                throw new ReceptionActionException('api.coworking.unavailable');
            }

            $session = $this->start($locked, $member);

            $locked->forceFill([
                'status' => ArrivalRequestStatus::Confirmed,
                'confirmed_by_user_id' => $operator->id,
                'confirmed_space_id' => $session->space_id,
            ])->save();

            // Sent once this commits: the member's waiting screen moves on,
            // and every desk screen drops the request from its queue.
            $this->realtime->send(
                new CoworkingStartConfirmed($locked, $session),
                new ArrivalRequestResolved($locked, ArrivalRequestStatus::Confirmed),
            );

            return $session->fresh();
        });
    }

    private function start(ArrivalRequest $request, User $member): Booking|WalkinSession
    {
        // The booking the member scanned for, or the one an early arrival
        // was going to wait for if it has come within check-in reach since.
        $booking = $request->matchedBooking ?? $this->bookingNowInReach($request->handoverBooking);

        if ($booking !== null) {
            $this->checkIns->checkIn($booking, SessionStartSource::Scan, $request->kiosk);

            return $booking;
        }

        // What the member agreed to at the kiosk, against their plan now: a
        // session they were told was covered and no longer is waits for them
        // to scan again and accept the price.
        $coverage = $this->coverage->evaluate($member, now());

        if (! $coverage->isCovered() && ! $request->accepted_uncovered) {
            throw new ReceptionActionException('api.coworking.coverage_changed', 409);
        }

        $session = $this->walkIns->start(
            $request->space,
            $member,
            SessionStartSource::Scan,
            $request->kiosk,
            ! $coverage->isCovered(),
            $coverage,
        )->session;

        if ($request->handover_booking_id !== null) {
            $session->forceFill(['handover_booking_id' => $request->handover_booking_id])->save();
        }

        return $session;
    }

    private function bookingNowInReach(?Booking $booking): ?Booking
    {
        if ($booking === null || $booking->status !== BookingStatus::Confirmed) {
            return null;
        }

        return $booking->start_at->lessThanOrEqualTo(now()->addMinutes(BookingCheckInService::EARLY_CHECK_IN_MINUTES))
            ? $booking
            : null;
    }
}
