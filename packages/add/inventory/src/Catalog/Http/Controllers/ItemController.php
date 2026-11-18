<?php

namespace Add\Inventory\Catalog\Http\Controllers;

use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Catalog\Http\Requests\StoreItemRequest;
use Add\Inventory\Catalog\Http\Requests\UpdateItemRequest;
use Add\Inventory\Catalog\Http\Resources\ItemResource;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Services\BarcodeGenerator;
use Add\Inventory\Catalog\Services\CatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class ItemController extends Controller
{
    /**
     * Filters: `category_id` (with every category below it), `kind`,
     * `search` (name, SKU or barcode), `active`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $items = Item::query()
            ->with(['category', 'unit', 'otherUnits.unit'])
            ->withSum('stockBalances', 'quantity')
            ->withCount('assets')
            ->when($request->filled('category_id'), function (Builder $query) use ($request) {
                $category = Category::query()->find($request->integer('category_id'));
                $query->whereIn('category_id', $category?->subtreeIds() ?? []);
            })
            ->when(ItemKind::tryFrom((string) $request->input('kind')), fn (Builder $query, ItemKind $kind) => $query->ofKind($kind))
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $needle = '%'.$request->string('search').'%';
                $query->where(fn (Builder $q) => $q->where('sku', 'like', $needle)->orWhere('barcode', 'like', $needle)->orWhere('name', 'like', $needle));
            })
            ->when($request->has('active'), fn (Builder $query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return ItemResource::collection($items);
    }

    public function store(StoreItemRequest $request, CatalogService $catalog): ItemResource
    {
        return new ItemResource($catalog->createItem($request->validated())->load(['category', 'unit', 'otherUnits.unit']));
    }

    public function show(Item $item): ItemResource
    {
        return new ItemResource($item->load(['category', 'unit', 'otherUnits.unit'])->loadSum('stockBalances', 'quantity')->loadCount('assets'));
    }

    /**
     * The item a scanned code belongs to.
     */
    public function byBarcode(string $barcode): ItemResource
    {
        return $this->show(Item::query()->where('barcode', $barcode)->firstOrFail());
    }

    /**
     * An unused internal barcode, for the form to fill in before saving.
     */
    public function generateBarcode(BarcodeGenerator $barcodes): JsonResponse
    {
        return response()->json(['data' => ['barcode' => $barcodes->next()]], 201);
    }

    public function update(UpdateItemRequest $request, Item $item, CatalogService $catalog): JsonResponse
    {
        $catalog->updateItem($item, $request->validated());

        return response()->json(['message' => __('inventory::catalog.item_updated')]);
    }

    public function destroy(Item $item, CatalogService $catalog): Response
    {
        $catalog->deleteItem($item);

        return response()->noContent();
    }
}
