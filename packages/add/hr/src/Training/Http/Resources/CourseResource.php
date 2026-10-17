<?php

namespace Add\Hr\Training\Http\Resources;

use Add\Hr\Training\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Course
 */
class CourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'provider' => $this->provider,
            'delivery' => $this->delivery->value,
            'duration_hours' => $this->duration_hours,
            'cost' => $this->cost,
            'currency' => $this->currency,
            'certification_name' => $this->certification_name,
            'certification_valid_months' => $this->certification_valid_months,
            'is_active' => $this->is_active,
        ];
    }
}
