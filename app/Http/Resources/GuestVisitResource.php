<?php

namespace App\Http\Resources;

use App\Domain\Booking\Models\GuestVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GuestVisit */
class GuestVisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'guest' => [
                'user_id' => $this->guest_user_id,
                'name' => $this->guest_name,
                'phone' => $this->guest_phone,
                'has_account' => $this->guest_user_id !== null,
            ],
            'host' => [
                'id' => $this->host_user_id,
                'name' => $this->host?->name,
            ],
            // Whether the session it becomes is billed to the host.
            'bills_host' => $this->billsHost(),
            'space_id' => $this->space_id,
            'arrived_at' => $this->arrived_at,
            'grace_ends_at' => $this->grace_ends_at,
            // For the desk's countdown; 0 once the grace is over.
            'grace_seconds_left' => max(0, (int) now()->diffInSeconds($this->grace_ends_at, false)),
            'host_declined_at' => $this->host_declined_at,
            'left_at' => $this->left_at,
            'converted_at' => $this->converted_at,
            'walkin_session_id' => $this->walkin_session_id,
        ];
    }
}
