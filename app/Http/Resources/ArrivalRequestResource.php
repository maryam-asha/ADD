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
        return [
            'id' => $this->id,
            'status' => $this->status,
            'requested_at' => $this->requested_at,
            'matched_booking_id' => $this->matched_booking_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ]),
            'matched_booking' => $this->whenLoaded('matchedBooking', fn () => $this->matchedBooking === null ? null : [
                'id' => $this->matchedBooking->id,
                'space_id' => $this->matchedBooking->space_id,
                'start_at' => $this->matchedBooking->start_at,
                'end_at' => $this->matchedBooking->end_at,
            ]),
        ];
    }
}
