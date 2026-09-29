<?php

namespace App\Http\Resources;

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
        ];
    }
}
