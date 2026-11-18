<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\OrgUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrgUnit
 */
class OrgUnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'head_employee_id' => $this->head_employee_id,
            'is_active' => $this->is_active,
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}
