<?php

namespace Add\Hr\Time\Http\Resources;

use Add\Hr\Time\Models\WorkPattern;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkPattern
 */
class WorkPatternResource extends JsonResource
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
            'kind' => $this->kind->value,
            // An object: JsonResource would turn numeric keys into a list.
            'weekly' => $this->weekly === null ? null : (object) $this->weekly,
            'weekly_hours' => $this->weekly_hours,
            'is_active' => $this->is_active,
        ];
    }
}
