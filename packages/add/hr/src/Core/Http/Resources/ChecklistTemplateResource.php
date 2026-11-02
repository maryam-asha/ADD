<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Enums\ChecklistAssignee;
use Add\Hr\Core\Models\ChecklistTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChecklistTemplate
 */
class ChecklistTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'name' => $this->name,
            // Items saved before assignees existed are HR's.
            'items' => array_map(fn (array $item) => [...$item, 'assignee' => $item['assignee'] ?? ChecklistAssignee::Hr->value], $this->items),
            'is_active' => $this->is_active,
        ];
    }
}
