<?php

namespace Add\Inventory\Catalog\Http\Controllers;

use Add\Inventory\Catalog\Http\Requests\CategoryRequest;
use Add\Inventory\Catalog\Http\Resources\CategoryResource;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->withCount('items')
            ->when($request->filled('kind'), fn ($query) => $query->where('kind', $request->string('kind')))
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(CategoryRequest $request, CatalogService $catalog): CategoryResource
    {
        return new CategoryResource($catalog->createCategory($request->validated()));
    }

    public function show(Category $category): CategoryResource
    {
        return new CategoryResource($category->load('attributeDefinitions')->loadCount('items'));
    }

    public function update(CategoryRequest $request, Category $category, CatalogService $catalog): JsonResponse
    {
        $catalog->updateCategory($category, $request->validated());

        return response()->json(['message' => __('inventory::catalog.category_updated')]);
    }

    public function destroy(Category $category, CatalogService $catalog): Response
    {
        $catalog->deleteCategory($category);

        return response()->noContent();
    }
}
