<?php

namespace Add\Hr\Recruitment\Http\Resources;

use Add\Hr\Recruitment\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Application
 */
class ApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'opening' => $this->whenLoaded('opening', fn () => ['id' => $this->opening->id, 'title' => $this->opening->title]),
            'candidate' => new CandidateResource($this->whenLoaded('candidate')),
            'stage' => $this->stage,
            'stage_changed_at' => $this->stage_changed_at?->toIso8601String(),
            'source' => $this->source->value,
            'referred_by' => $this->whenLoaded('referredBy', fn () => $this->referredBy === null ? null : [
                'id' => $this->referredBy->id,
                'employee_number' => $this->referredBy->employee_number,
                'name' => $this->referredBy->name,
            ]),
            'cover_letter' => $this->cover_letter,
            'cv' => $this->cv_path === null ? null : ['name' => $this->cv_name, 'mime' => $this->cv_mime, 'size' => $this->cv_size],
            'rejection_reason' => $this->rejection_reason,
            'employee_id' => $this->employee_id,
            'history' => $this->whenLoaded('stageChanges', fn () => $this->stageChanges->map(fn ($change) => [
                'from' => $change->from_stage,
                'to' => $change->to_stage,
                'note' => $change->note,
                'by' => $change->changed_by,
                'at' => $change->created_at?->toIso8601String(),
            ])),
            'interviews' => InterviewResource::collection($this->whenLoaded('interviews')),
            'offers' => OfferResource::collection($this->whenLoaded('offers')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
