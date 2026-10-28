<?php

namespace App\Http\Resources;

use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\ArrivalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * coworking-sessions/current: the running session (state active), or the
 * kiosk scan waiting for reception to start it (state awaiting_confirmation)
 * — one shape for both, like the home card, so the timer reads the same
 * keys before and after reception confirms. While awaiting there is no
 * session yet: its own fields (id, reference, check-in, end request,
 * payment, discounts) are empty, and arrival_request_id is set instead.
 */
class CurrentCoworkingSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof ArrivalRequest) {
            return [
                'state' => 'active',
                'arrival_request_id' => null,
                'requested_at' => null,
            ] + (new CoworkingSessionResource($this->resource))->resolve($request);
        }

        $arrival = $this->resource;

        return [
            'state' => 'awaiting_confirmation',
            'arrival_request_id' => $arrival->id,
            'requested_at' => $arrival->requested_at?->toIso8601String(),
            // The session reception's confirmation starts: the matched booking's check-in, else a walk-in.
            'type' => $arrival->matched_booking_id !== null ? 'booking' : 'walkin',
            'id' => null,
            'reference' => null,
            'space' => $arrival->space === null ? null : [
                'id' => $arrival->space->id,
                'name' => $arrival->space->translate('name'),
            ],
            'checked_in_at' => null,
            'checked_out_at' => null,
            'started_via' => SessionStartSource::Scan->value,
            'hourly_rate_points' => $arrival->space?->hourly_rate_points,
            // What the member was shown at the kiosk; the session keeps it once started.
            'coverage' => $arrival->coverage_snapshot !== null
                ? new SessionCoverageResource($arrival->coverage_snapshot)
                : null,
            'accepted_uncovered' => $arrival->accepted_uncovered,
            'upcoming_booking' => $arrival->handoverBooking !== null ? [
                'id' => $arrival->handoverBooking->id,
                'start_at' => $arrival->handoverBooking->start_at->toIso8601String(),
                'end_at' => $arrival->handoverBooking->end_at->toIso8601String(),
            ] : null,
            'end_request' => null,
            'payment' => null,
            'discounts' => [],
        ];
    }
}
