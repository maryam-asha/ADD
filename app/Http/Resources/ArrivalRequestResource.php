<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArrivalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $coworking = $this->isCoworkingStart();

        return [
            'id' => $this->id,
            // arrival: reception checks a booking in (or picks a space);
            // coworking_start: confirming starts the coworking session the
            // member scanned for, on `space`, with nothing else to choose.
            'purpose' => $this->purpose,
            'status' => $this->status,
            'requested_at' => $this->requested_at,
            'matched_booking_id' => $this->matched_booking_id,
            'space' => $coworking && $this->space !== null ? [
                'id' => $this->space->id,
                'name' => $this->space->translate('name'),
            ] : null,
            // What the member was shown about their plan at the kiosk, and
            // whether they agreed to pay the hourly rate.
            'coverage' => $coworking && $this->coverage_snapshot !== null
                ? new SessionCoverageResource($this->coverage_snapshot)
                : null,
            'accepted_uncovered' => $coworking && $this->accepted_uncovered,
            // An early arrival: the walk-in this starts becomes this booking when it begins.
            'upcoming_booking' => $coworking && $this->handoverBooking !== null ? [
                'id' => $this->handoverBooking->id,
                'start_at' => $this->handoverBooking->start_at->toIso8601String(),
                'end_at' => $this->handoverBooking->end_at->toIso8601String(),
            ] : null,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
                // So reception can see it is the person at the desk.
                'avatar_url' => $this->user->personalProfile?->avatar_url,
            ]),
            'matched_booking' => $this->whenLoaded('matchedBooking', fn () => $this->matchedBooking === null ? null : [
                'id' => $this->matchedBooking->id,
                'space_id' => $this->matchedBooking->space_id,
                'space_type' => $this->matchedBooking->space?->space_type,
                'space_name' => $this->matchedBooking->space?->translate('name'),
                'start_at' => $this->matchedBooking->start_at,
                'end_at' => $this->matchedBooking->end_at,
            ]),
        ];
    }
}
