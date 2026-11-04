<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\CoworkingStartResult;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\SessionCoverageEvaluator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
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
    /** How far ahead a room, office or hall booking turns a scan into a word to reception. */
    public const NON_COWORKING_ARRIVAL_MINUTES = 60;

    /**
     * A member with no plan in this many days is not asked to accept an
     * uncovered session: they know they pay, so the scan goes straight to
     * reception. A plan that lapsed more recently is explained first.
     */
    public const LAPSED_PLAN_DAYS = 15;

    public function __construct(
        private readonly KioskCodeVerifier $codes,
        private readonly ScanTicketService $tickets,
        private readonly ArrivalRequestMatcher $bookings,
        private readonly SessionCoverageEvaluator $coverage,
        private readonly WalkInCapacityService $walkIns,
        private readonly BusinessHoursService $businessHours,
        private readonly OpenCoworkingSessionLocator $openSessions,
        private readonly ArrivalRequestService $arrivals,
        private readonly MembershipResolver $memberships,
        private readonly BookingCheckInService $checkIns,
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

            if ($booking !== null && $booking->start_at->greaterThan(now()->addMinutes($this->checkIns->earlyWindowMinutesFor($booking)))) {
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

            // Without a plan in LAPSED_PLAN_DAYS the scan itself is the
            // acceptance: the request is filed as uncovered, unasked.
            if (! $coverage->isCovered() && $scanTicket === null
                && $this->memberships->hasHeldSinceFor($member, now()->subDays(self::LAPSED_PLAN_DAYS))) {
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
            'from' => $start->copy()->subMinutes($this->checkIns->earlyWindowMinutesFor($booking))->format('H:i'),
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

    /**
     * The member's ended sessions on a coworking space — walk-ins and
     * checked-in bookings alike, as OpenCoworkingSessionLocator counts them —
     * newest first, one list paged across both tables. Each page holds the
     * models, with what a card reads loaded.
     *
     * @return LengthAwarePaginator<int, Booking|WalkinSession>
     */
    public function pastSessionsFor(User $member, ?PaymentState $paymentState, int $perPage): LengthAwarePaginator
    {
        $ended = fn (Builder $query, string $type) => $query
            ->select([DB::raw("'{$type}' as type"), 'id', 'checked_in_at'])
            ->where('user_id', $member->id)
            ->whereNotNull('checked_in_at')
            ->whereNotNull('checked_out_at')
            ->whereHas('space', fn (Builder $space) => $space->where('space_type', SpaceType::CoSpace))
            ->when($paymentState, fn (Builder $q, PaymentState $state) => $q->where('payment_state', $state->value))
            ->toBase();

        $page = DB::query()
            ->fromSub($ended(WalkinSession::query(), 'walkin')->unionAll($ended(Booking::query(), 'booking')), 'sessions')
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $rows = collect($page->items());
        $load = fn (string $model, string $type) => $model::query()
            ->with(['space', 'payments'])
            ->findMany($rows->where('type', $type)->pluck('id'))
            ->keyBy('id');
        $models = ['walkin' => $load(WalkinSession::class, 'walkin'), 'booking' => $load(Booking::class, 'booking')];

        return $page->setCollection($rows->map(fn (object $row) => $models[$row->type]->get($row->id))->filter()->values());
    }
}
