<?php

namespace Add\Hr\Timesheets\Http\Resources;

use Add\Hr\Timesheets\Models\CostAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CostAllocation
 */
class CostAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cost_center' => ['id' => $this->costCenter->id, 'code' => $this->costCenter->code, 'name' => $this->costCenter->name],
            'percent' => $this->percent,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
        ];
    }
}
