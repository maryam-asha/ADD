<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'space_id' => $this->space_id,
            'type' => $this->type,
            'external_ref' => $this->external_ref,
            'metadata' => $this->metadata,
            'status' => $this->status,
        ];
    }
}
