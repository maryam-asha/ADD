<?php

namespace Add\Hr\Core\Http\Resources;

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
            'items' => $this->items,
            'is_active' => $this->is_active,
        ];
    }
}
