<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeatDeskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'code' => $this->code,
            'label' => $this->label,
            'qr_point_id' => $this->qr_point_id,
            // The Dedicated Desk subscription holding it now; only on reads
            // that ask DedicatedDeskService for it.
            'membership_id' => $this->when(
                array_key_exists('membership_id', $this->resource->getAttributes()),
                fn () => $this->membership_id === null ? null : (int) $this->membership_id,
            ),
        ];
    }
}
