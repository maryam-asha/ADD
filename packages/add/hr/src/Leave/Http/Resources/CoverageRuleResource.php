<?php

namespace Add\Hr\Leave\Http\Resources;

use Add\Hr\Leave\Models\CoverageRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CoverageRule
 */
class CoverageRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'org_unit_id' => $this->org_unit_id,
            'position_id' => $this->position_id,
            'min_present' => $this->min_present,
            'is_active' => $this->is_active,
        ];
    }
}
