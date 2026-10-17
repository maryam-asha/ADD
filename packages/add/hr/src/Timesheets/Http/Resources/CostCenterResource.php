<?php

namespace Add\Hr\Timesheets\Http\Resources;

use Add\Hr\Timesheets\Models\CostCenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CostCenter
 */
class CostCenterResource extends JsonResource
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
            'costable_type' => $this->costable_type,
            'costable_id' => $this->costable_id,
            'is_active' => $this->is_active,
        ];
    }
}
