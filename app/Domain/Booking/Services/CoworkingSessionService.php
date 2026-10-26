<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\CoworkingStartResult;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\SessionCoverageEvaluator;
use Illuminate\Support\Facades\DB;

/**
 * Self-service coworking sessions (docs/decisions/coworking-self-service-sessions.md).
 * A scan files a request reception confirms; the session starts at that
 * confirmation (CoworkingStartConfirmation).
 *
 * Orchestration only: every rule that decides whether a session may start —
 * business hours, capacity, booking status — stays in the service that
 * already enforces it for reception, so the desk and the scan cannot
 * disagree.
 */
class CoworkingSessionService
{
    /** How long before its start a scan may check a booking in: the same window as every check-in. */
    public const EARLY_CHECK_IN_MINUTES = BookingCheckInService::EARLY_CHECK_IN_MINUTES;

    /** How far ahead a room, office or hall booking turns a scan into a word to reception. */
    public const NON_COWORKING_ARRIVAL_MINUTES = 60;

    public function __construct(
        private readonly KioskCodeVerifier $codes,
        private readonly ScanTicketService $tickets,
        private readonly ArrivalRequestMatcher $bookings,
        private readonly SessionCoverageEvaluator $coverage,
        private readonly WalkInCapacityService $walkIns,
        private readonly BusinessHoursService $businessHours,
        private readonly OpenCoworkingSessionLocator $openSessions,
        private readonly ArrivalRequestService $arrivals,
    ) {}

    /**
     * Exactly one of $qrValue (the first scan) or $scanTicket (the member
     * accepting an uncovered session) is given — ScanRequest
     * enforces it. A ticket is only issued after the member was shown why the
     * plan will not cover them, so presenting one is the acceptance.
     */
    public function start(User $member, ?string $qrValue = null, ?string $scanTicket = null): CoworkingStartResult
    {
        $kiosk = $qrValue !== null
            ? $this->codes->verify($qrValue)
            : $this->tickets->redeem($scanTicket, $member);

        $space = $kiosk->space;

        if ($space->space_type !== SpaceType::CoSpace || $space->status !== OperationalStatus::Active) {
            throw new ReceptionActionException('api.coworking.unavailable');
        }

        // Checked here as well as inside the walk-in/check-in services, so a
        // member is never asked to accept a paid session that would then be
        // refused for the hour.
        if (! $this->businessHours->isWithinBusinessHours(now(), $space->building->branch)) {
            throw new ReceptionActionException('api.reception.outside_business_hours');
        }

        return DB::transaction(function () use ($member, $kiosk, $space, $scanTicket) {
            // Serializes two scans by the same member (a double tap), so the
            // open-session check below cannot be raced into two sessions.
            User::query()->whereKey($member->id)->lockForUpdate()->first();

            if ($this->openSessionFor($member) !== null) {
                throw new ReceptionActionException('api.coworking.already_in_session', 409);
            }

            // Already waiting on reception: scanning again changes nothing.
            if (($pending = $this->arrivals->pendingFor($member)) !== null) {
                return $pending->isCoworkingStart()
                    ? CoworkingStartResult::awaitingConfirmation($pending)
                    : CoworkingStartResult::arrivalRequested($pending, $pending->matchedBooking);
            }

            $branch = $space->building->branch;
            $booking = $this->bookings->matchBookingFor($member, $branch, now(), $space);

            // The matcher returns today's earliest booking that has not ended,
            // however far off it starts. A scan well before it is not that
            // booking's arrival — checking it in would bill every hour until
            // then at the booking's rate — so the member sits on a walk-in
            // that hands over to the booking when it starts (a pending
            // booking outside the window included; only a confirmed one is
            // handed over to). Either way nothing starts until reception
            // confirms the member is here.
            $handoverBooking = null;

            if ($booking !== null && $booking->start_at->greaterThan(now()->addMinutes(self::EARLY_CHECK_IN_MINUTES))) {
                $handoverBooking = $booking;
                $booking = null;
            }

            if ($booking !== null) {
                // No fallback to a walk-in: that would route around approval
                // (docs/decisions/kiosk-display.md, same rule).
                if ($booking->status === BookingStatus::AwaitingPayment) {
                    throw new ReceptionActionException('api.coworking.booking_awaiting_payment', 409);
                }

                if ($booking->status === BookingStatus::Pending) {
                    throw new ReceptionActionException('api.coworking.booking_pending_approval', 409);
                }

                return CoworkingStartResult::awaitingConfirmation($this->arrivals->coworkingStart($member, $kiosk, $booking));
            }

            // A room, office or hall booking is reception's to check in, as
            // at the desk: the scan only tells reception the member is here,
            // rather than opening a coworking session they did not come for.
            $elsewhere = $this->bookings->nearNonCoworkingBookingFor($member, $branch, now(), self::NON_COWORKING_ARRIVAL_MINUTES);

            if ($elsewhere !== null) {
                return CoworkingStartResult::arrivalRequested($this->arrivals->request($member, $elsewhere), $elsewhere);
            }

            $coverage = $this->coverage->evaluate($member, now());

            if (! $coverage->isCovered() && $scanTicket === null) {
                return CoworkingStartResult::confirmationRequired(
                    $coverage,
                    $this->tickets->issue($member, $kiosk),
                    (int) ($space->hourly_rate_points ?? 0),
                );
            }

            // The walk-in's own rules, now, so the member is refused at the
            // kiosk rather than after reception confirms; they are applied
            // again when the walk-in actually starts.
            try {
                $this->walkIns->assertCanStart($space, $member);
            } catch (ReceptionActionException $e) {
                // No seat to wait in: their booking still holds one from its start.
                if ($handoverBooking !== null && $e->messageKey === 'api.reception.no_capacity') {
                    throw $this->tooEarly($handoverBooking, $branch->timezone);
                }

                throw $e;
            }

            return CoworkingStartResult::awaitingConfirmation($this->arrivals->coworkingStart(
                $member,
                $kiosk,
                null,
                $handoverBooking,
                ! $coverage->isCovered(),
                $coverage,
            ));
        });
    }

    private function tooEarly(Booking $booking, string $timezone): ReceptionActionException
    {
        $start = $booking->start_at->copy()->setTimezone($timezone);

        return new ReceptionActionException('api.coworking.booking_too_early', 409, [
            'start' => $start->format('H:i'),
            'from' => $start->copy()->subMinutes(self::EARLY_CHECK_IN_MINUTES)->format('H:i'),
        ]);
    }

    /** A session that started, by the `type` segment of the report URLs — "walkin" or "booking"; a booking never checked in is not one. */
    public function find(string $type, int $id): Booking|WalkinSession|null
    {
        return match ($type) {
            'walkin' => WalkinSession::find($id),
            'booking' => Booking::query()->whereKey($id)->whereNotNull('checked_in_at')->first(),
            default => null,
        };
    }

    /** The member's checked-in, not yet checked-out session on a coworking space, if any. */
    public function openSessionFor(User $member): Booking|WalkinSession|null
    {
        return $this->openSessions->for($member);
    }
}
