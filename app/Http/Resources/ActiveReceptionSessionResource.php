<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\BookingPresentationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiveReceptionSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'space_id' => $this->space_id,
            'space_type' => $this->space?->space_type,
            'user_id' => $this->user_id,
            'user_name' => $this->user?->name,
            'checked_in_at' => $this->checked_in_at,
            'is_overdue' => $this->is_overdue,
            // A walk-in only: whether it sits in a seat its subscription
            // holds (true) or took a free one from the co-working pool
            // (false). Null for a booking, whose seat is the booking
            // (docs/decisions/coworking-seat-pool.md).
            'seat_covered' => $this->type === 'walkin' ? (bool) $this->seat_covered : null,
            'membership_id' => $this->type === 'walkin' ? $this->membership_id : null,
            // The codes of the desks this member's Dedicated Desk
            // subscription (their own or their company's) ties them to.
            'desk_codes' => $this->desk_codes ?? [],
            // Where to send them: the room lent to co-working a booking was
            // placed in, else the session's own space (a walk-in into a lent
            // room is already on it).
            'seat' => $this->space !== null
                ? app(BookingPresentationService::class)->seat($this->resource instanceof Booking ? $this->resource->seatingSpace() : $this->space, app()->getLocale())
                : null,
            'started_via' => $this->resource->startSource()->value,
            // What reception checks against the person in front of them.
            'member' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'phone' => $this->user?->phone,
                'avatar_url' => $this->user?->personalProfile?->avatar_url,
                'company' => $this->user?->companies->first()?->legal_name,
            ],
            'plan' => $this->resource instanceof WalkinSession && $this->coverage_snapshot !== null
                ? new SessionCoverageResource($this->coverage_snapshot)
                : null,
            'accepted_uncovered' => $this->resource instanceof WalkinSession && $this->accepted_uncovered,
            'end_request' => $this->pendingEndRequest === null
                ? null
                : new SessionEndRequestResource($this->pendingEndRequest),
        ];
    }
}
