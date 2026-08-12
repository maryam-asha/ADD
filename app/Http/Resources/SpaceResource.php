<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'zone_id' => $this->zone_id,
            'space_type' => $this->space_type,
            'allocation_model' => $this->allocation_model,
            'is_lockable' => $this->is_lockable,
            'capacity' => $this->capacity,
            'hourly_rate' => $this->hourly_rate,
            'pricing_currency' => $this->pricing_currency,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'status_from' => $this->status_from,
            'status_until' => $this->status_until,
        ];
    }
}
