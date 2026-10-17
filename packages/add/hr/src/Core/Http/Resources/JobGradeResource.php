<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\JobGrade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobGrade
 */
class JobGradeResource extends JsonResource
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
            'rank' => $this->rank,
        ];
    }
}
