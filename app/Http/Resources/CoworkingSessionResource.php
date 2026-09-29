<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\WalkinSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The member app's view of a coworking session — either a walk-in or a checked-in booking. */
class CoworkingSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $session = $this->resource;
        $isWalkin = $session instanceof WalkinSession;

        return [
            'type' => $session->coworkingType(),
            'id' => $session->id,
            'space' => [
                'id' => $session->space->id,
                'name' => $session->space->translate('name'),
            ],
            'checked_in_at' => $session->checked_in_at?->toIso8601String(),
            'checked_out_at' => $session->checked_out_at?->toIso8601String(),
            'started_via' => $session->startSource()->value,
            'hourly_rate_points' => $session->space->hourly_rate_points,
            // Bookings carry their plan benefit in the price fixed at creation.
            'coverage' => $isWalkin && $session->coverage_snapshot !== null
                ? new SessionCoverageResource($session->coverage_snapshot)
                : null,
            'accepted_uncovered' => $isWalkin && $session->accepted_uncovered,
            'end_request' => $session->pendingEndRequest === null
                ? null
                : new SessionEndRequestResource($session->pendingEndRequest),
        ];
    }
}
