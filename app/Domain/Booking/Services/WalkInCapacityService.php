<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\DataTransferObjects\WalkInStartResult;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Membership\Services\SessionCoverageEvaluator;
use App\Domain\Membership\ValueObjects\SessionCoverage;
use Illuminate\Support\Facades\DB;

class WalkInCapacityService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly MembershipResolver $memberships,
        private readonly PlanHourCoverageService $planHours,
        private readonly SessionCoverageEvaluator $coverage,
        private readonly OpenCoworkingSessionLocator $openSessions,
    ) {}

    /**
     * "Space has available capacity right now" is a physical-presence count
     * (currently checked-in-and-not-checked-out bookings + walk-ins),
     * compared against Space.capacity — not a reservation/slot-overlap
     * count (that's space_capacity_slots, out of scope this phase). A
     * confirmed booking that hasn't checked in yet does not count against
     * a walk-in's capacity check — flagged in the decision doc as an
     * assumption to revisit when the full capacity-slot system lands.
     * `capacity === null` is treated as unlimited: a space with no
     * configured capacity shouldn't block every walk-in.
     */
    public function start(
        Space $space,
        User $member,
        SessionStartSource $via = SessionStartSource::Reception,
        ?Kiosk $kiosk = null,
        bool $acceptedUncovered = false,
        ?SessionCoverage $coverage = null,
    ): WalkInStartResult {
        return DB::transaction(function () use ($space, $member, $via, $kiosk, $acceptedUncovered, $coverage) {
            $locked = Space::query()->whereKey($space->id)->lockForUpdate()->firstOrFail();

            // At most one open coworking session per member, whichever path
            // starts it (docs/decisions/coworking-self-service-sessions.md);
            // the scan returns the open one before it ever gets here. Rooms
            // and the other space types are not coworking sessions.
            if ($locked->space_type === SpaceType::CoSpace && $this->openSessions->for($member) !== null) {
                throw new ReceptionActionException('api.reception.member_already_in_session', 409);
            }

            $branch = $locked->building->branch;

            if (! $this->businessHours->isWithinBusinessHours(now(), $branch)) {
                throw new ReceptionActionException('api.reception.outside_business_hours');
            }

            if ($locked->capacity !== null) {
                $occupied = Booking::query()
                    ->where('space_id', $locked->id)
                    ->whereNotNull('checked_in_at')
                    ->whereNull('checked_out_at')
                    ->count();

                $occupied += WalkinSession::query()
                    ->where('space_id', $locked->id)
                    ->whereNull('checked_out_at')
                    ->count();

                if ($occupied >= $locked->capacity) {
                    throw new ReceptionActionException('api.reception.no_capacity');
                }
            }

            // Stamped now, not re-resolved at checkout: it pins which
            // subscription was in force when the session started.
            $membership = $this->memberships->activeFor($member);

            $session = WalkinSession::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'membership_id' => $membership?->id,
                'checked_in_at' => now(),
                'payment_state' => PaymentState::Unpaid,
                'started_via' => $via,
                'kiosk_id' => $kiosk?->id,
                'accepted_uncovered' => $acceptedUncovered,
                // Taken for every walk-in, reception or scan, so the reception
                // list shows the same plan picture for both. A scan passes the
                // coverage it already showed the member, so the snapshot is
                // exactly what they agreed to.
                'coverage_snapshot' => ($coverage ?? $this->coverage->evaluate($member, now()))->toArray(),
            ]);

            // A plan with an access window that isn't open right now does not
            // block the session — it just isn't covering it, which reception
            // needs told. Anyone without such a plan gets no notice at all,
            // since nothing about their session changed.
            $plan = $membership?->plan;

            if ($plan === null || ! $plan->hasAccessWindow()) {
                return new WalkInStartResult($session, false);
            }

            $applies = $this->planHours->coverableSeconds(
                $membership,
                $session->checked_in_at,
                $session->checked_in_at->copy()->addMinute(),
            ) > 0;

            return new WalkInStartResult(
                $session,
                $applies,
                $applies ? null : 'api.reception.outside_plan_window',
            );
        });
    }
}
