<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'name' => $this->name,
            'category' => $this->category,
            'quantity' => $this->quantity,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'status_from' => $this->status_from,
            'status_until' => $this->status_until,
        ];
    }
}
