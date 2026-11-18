<?php

namespace Add\Inventory\Catalog\Http\Controllers;

use Add\Inventory\Catalog\Http\Requests\AttributeDefinitionRequest;
use Add\Inventory\Catalog\Http\Resources\AttributeDefinitionResource;
use Add\Inventory\Catalog\Models\AttributeDefinition;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * A category's own fields (its inherited ones are on GET categories/{id}).
 * Removing a field leaves the answers items already gave in place, unread.
 */
class AttributeDefinitionController extends Controller
{
    public function store(AttributeDefinitionRequest $request, Category $category, CatalogService $catalog): AttributeDefinitionResource
    {
        return new AttributeDefinitionResource($catalog->defineAttribute($category, $request->validated()));
    }

    public function update(AttributeDefinitionRequest $request, AttributeDefinition $attributeDefinition): JsonResponse
    {
        $attributeDefinition->update($request->validated());

        return response()->json(['message' => __('inventory::catalog.field_updated')]);
    }

    public function destroy(AttributeDefinition $attributeDefinition): Response
    {
        $attributeDefinition->delete();

        return response()->noContent();
    }
}
