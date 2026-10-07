<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\CoworkingStartResult;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
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
 * Orchestration only: every rule that decides whether a session may start —
 * business hours, capacity, booking status — stays in the service that
 * already enforces it for reception, so the desk and the scan cannot
 * disagree.
 */
class CoworkingSessionService
{
    /** How long before its start a scan may check a booking in: the same window as every check-in. */
    public const EARLY_CHECK_IN_MINUTES = BookingCheckInService::EARLY_CHECK_IN_MINUTES;

    public function __construct(
        private readonly KioskCodeVerifier $codes,
        private readonly ScanTicketService $tickets,
        private readonly ArrivalRequestMatcher $bookings,
        private readonly BookingCheckInService $checkIns,
        private readonly SessionCoverageEvaluator $coverage,
        private readonly WalkInCapacityService $walkIns,
        private readonly BusinessHoursService $businessHours,
        private readonly OpenCoworkingSessionLocator $openSessions,
    ) {}

    /**
     * Exactly one of $qrValue (the first scan) or $scanTicket (the member
     * accepting an uncovered session) is given — StartCoworkingSessionRequest
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

            if (($open = $this->openSessionFor($member)) !== null) {
                return CoworkingStartResult::existing($open);
            }

            $booking = $this->bookings->matchBookingFor($member, $space->building->branch, now(), $space);

            // The matcher returns today's earliest booking that has not ended,
            // however far off it starts. A scan well before it is not that
            // booking's arrival — checking it in would bill every hour until
            // then at the booking's rate — so it counts as no booking at all
            // and the member goes down the walk-in path (a pending booking
            // outside the window included).
            if ($booking !== null && $booking->start_at->greaterThan(now()->addMinutes(self::EARLY_CHECK_IN_MINUTES))) {
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

                $this->checkIns->checkIn($booking, SessionStartSource::Scan, $kiosk);

                return CoworkingStartResult::started($booking->fresh());
            }

            $coverage = $this->coverage->evaluate($member, now());

            if (! $coverage->isCovered() && $scanTicket === null) {
                return CoworkingStartResult::confirmationRequired(
                    $coverage,
                    $this->tickets->issue($member, $kiosk),
                    (int) ($space->hourly_rate_points ?? 0),
                );
            }

            $result = $this->walkIns->start($space, $member, SessionStartSource::Scan, $kiosk, ! $coverage->isCovered(), $coverage);

            return CoworkingStartResult::started($result->session->fresh(), $coverage);
        });
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
