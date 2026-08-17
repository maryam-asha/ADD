<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

class WalkInCapacityService
{
    public function __construct(private readonly BusinessHoursService $businessHours) {}

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
    public function start(Space $space, User $member): WalkinSession
    {
        return DB::transaction(function () use ($space, $member) {
            $locked = Space::query()->whereKey($space->id)->lockForUpdate()->firstOrFail();

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

            return WalkinSession::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'checked_in_at' => now(),
                'payment_state' => PaymentState::Unpaid,
            ]);
        });
    }
}
