<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\ChecklistTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChecklistTask
 */
class ChecklistTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'event_id' => $this->event_id,
            'title' => $this->title,
            'due_on' => $this->due_on?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->completed_by,
            'notes' => $this->notes,
        ];
    }
}
