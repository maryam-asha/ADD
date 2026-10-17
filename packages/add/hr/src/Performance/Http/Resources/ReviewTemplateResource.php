<?php

namespace Add\Hr\Performance\Http\Resources;

use Add\Hr\Performance\Models\ReviewTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReviewTemplate
 */
class ReviewTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scale_max' => $this->scale_max,
            'competencies' => $this->competencies,
            'goals_weight' => $this->goals_weight,
            'is_active' => $this->is_active,
        ];
    }
}
