<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\ChecklistTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A checklist task as its employee sees it: HR's notes and who ticked it
 * stay with HR (ChecklistTaskResource).
 *
 * @mixin ChecklistTask
 */
class MyTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'due_on' => $this->due_on?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
