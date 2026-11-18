<?php

namespace Add\Hr\Recruitment\Http\Resources;

use Add\Hr\Recruitment\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Candidate
 */
class CandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'source' => $this->source->value,
            'employee_id' => $this->employee_id,
            'consent_at' => $this->consent_at?->toIso8601String(),
            'notes' => $this->notes,
            'applications' => ApplicationResource::collection($this->whenLoaded('applications')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
