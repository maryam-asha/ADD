<?php

namespace Add\Inventory\Catalog\Http\Resources;

use Add\Inventory\Catalog\Models\AttributeDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttributeDefinition
 */
class AttributeDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'options' => $this->options,
            'unit' => $this->unit,
            'is_required' => $this->is_required,
            'order' => $this->order,
        ];
    }
}
