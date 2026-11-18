<?php

namespace Add\Hr\Time\Http\Resources;

use Add\Hr\Time\Models\ShiftTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShiftTemplate
 */
class ShiftTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'crosses_midnight' => $this->crossesMidnight(),
            'break_minutes' => $this->break_minutes,
            'planned_minutes' => $this->plannedMinutes(),
            'is_active' => $this->is_active,
        ];
    }
}
