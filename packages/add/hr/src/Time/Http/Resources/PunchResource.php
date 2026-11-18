<?php

namespace Add\Hr\Time\Http\Resources;

use Add\Hr\Time\Models\Punch;
use Add\Hr\Time\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Punch
 */
class PunchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'punched_at' => LocalTime::iso($this->punched_at),
            'source' => $this->source->value,
            'device_type' => $this->device_type,
            'device_id' => $this->device_id,
            'reason' => $this->reason,
            'recorded_by' => $this->recorded_by,
        ];
    }
}
