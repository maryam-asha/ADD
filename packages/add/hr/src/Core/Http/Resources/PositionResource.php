<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\Position;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Position
 */
class PositionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'org_unit_id' => $this->org_unit_id,
            'job_grade_id' => $this->job_grade_id,
            'code' => $this->code,
            'title' => $this->title,
            'headcount' => $this->headcount,
            // Lists load it with withCount; single reads count it here.
            'occupied' => (int) ($this->resource->getAttribute('occupied') ?? $this->occupiedCount()),
            'is_active' => $this->is_active,
        ];
    }
}
