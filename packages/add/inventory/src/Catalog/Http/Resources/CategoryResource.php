<?php

namespace Add\Inventory\Catalog\Http\Resources;

use Add\Inventory\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A flat node: the client builds the tree from `parent_id`. `fields` (on
 * show) are every field its items answer, inherited ones included.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'icon' => $this->icon,
            'show_to_members' => $this->show_to_members,
            'order' => $this->order,
            'items_count' => $this->whenCounted('items'),
            'fields' => $this->when(
                $this->relationLoaded('attributeDefinitions'),
                fn () => $this->effectiveDefinitions()->map(fn ($definition) => [
                    ...(new AttributeDefinitionResource($definition))->resolve($request),
                    'inherited' => $definition->category_id !== $this->id,
                ])->values(),
            ),
        ];
    }
}
