<?php

namespace Add\Hr\Core\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Add\Hr\Core\Models\EmploymentEvent
 */
class EmploymentEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'effective_on' => $this->effective_on->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'payload' => $this->payload,
            'recorded_by' => $this->recorded_by,
            'recorded_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
