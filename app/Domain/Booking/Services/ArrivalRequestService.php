<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\ArrivalRequestPurpose;
use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Events\ArrivalRequestCreated;
use App\Domain\Booking\Events\CoworkingStartRequested;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\ValueObjects\SessionCoverage;
use App\Services\Realtime\RealtimeBroadcaster;

/**
 * What a kiosk scan leaves for reception to confirm (docs/decisions/kiosk-display.md,
 * docs/decisions/coworking-self-service-sessions.md): "I'm here" for a
 * booking reception checks in, or a coworking session that starts when
 * reception confirms it. At most one pending request per member, whichever
 * kind; wasRecentlyCreated tells the caller whether this one is new, and
 * only a new one is announced to the desk (arrival_request.created).
 */
class ArrivalRequestService
{
    public function __construct(private readonly RealtimeBroadcaster $realtime) {}

    public function request(User $member, ?Booking $matchedBooking): ArrivalRequest
    {
        return $this->pendingFor($member) ?? $this->file([
            'user_id' => $member->id,
            // Set here, not left to the column defaults, so the response
            // carries them.
            'purpose' => ArrivalRequestPurpose::Arrival,
            'status' => ArrivalRequestStatus::Pending,
            'requested_at' => now(),
            'matched_booking_id' => $matchedBooking?->id,
        ]);
    }

    /**
     * $booking is the coworking booking reception's confirmation checks in;
     * without one the confirmation starts a walk-in, handing over to
     * $handoverBooking when that starts. $coverage is what the member was
     * shown, $acceptedUncovered whether they agreed to pay.
     */
    public function coworkingStart(
        User $member,
        Kiosk $kiosk,
        ?Booking $booking,
        ?Booking $handoverBooking = null,
        bool $acceptedUncovered = false,
        ?SessionCoverage $coverage = null,
    ): ArrivalRequest {
        return $this->pendingFor($member) ?? $this->file([
            'user_id' => $member->id,
            'purpose' => ArrivalRequestPurpose::CoworkingStart,
            'kiosk_id' => $kiosk->id,
            'space_id' => $kiosk->space_id,
            'status' => ArrivalRequestStatus::Pending,
            'requested_at' => now(),
            'matched_booking_id' => $booking?->id,
            'handover_booking_id' => $handoverBooking?->id,
            'accepted_uncovered' => $acceptedUncovered,
            'coverage_snapshot' => $coverage?->toArray(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function file(array $attributes): ArrivalRequest
    {
        $request = ArrivalRequest::create($attributes);

        // After the scan's transaction commits, so the desk's re-fetch finds
        // it — and the member's other screens (home) for a coworking start.
        $this->realtime->send(...array_filter([
            new ArrivalRequestCreated($request),
            $request->isCoworkingStart() ? new CoworkingStartRequested($request) : null,
        ]));

        return $request;
    }

    public function pendingFor(User $member): ?ArrivalRequest
    {
        return ArrivalRequest::query()
            ->where('user_id', $member->id)
            ->where('status', ArrivalRequestStatus::Pending)
            ->first();
    }
}
