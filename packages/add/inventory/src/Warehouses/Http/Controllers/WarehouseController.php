<?php

namespace Add\Inventory\Warehouses\Http\Controllers;

use Add\Inventory\Inventory;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Http\Requests\WarehouseRequest;
use Add\Inventory\Warehouses\Http\Resources\WarehouseResource;
use Add\Inventory\Warehouses\Models\Warehouse;
use Add\Inventory\Warehouses\Services\WarehouseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class WarehouseController extends Controller
{
    /**
     * Primary ones first. Filters: `search` (name, code, address),
     * `active`, `manager_id`, `allows_sales`, `place[type]` + `place[id]`
     * (warehouses sitting anywhere inside that place).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $place = $request->input('place');

        $warehouses = Warehouse::query()
            ->withCount(['balances' => fn (Builder $query) => $query->where('quantity', '>', 0)])
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $needle = '%'.$request->string('search').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $needle)->orWhere('code', 'like', $needle)->orWhere('address', 'like', $needle));
            })
            ->when($request->has('active'), fn (Builder $query) => $query->where('is_active', $request->boolean('active')))
            ->when($request->has('allows_sales'), fn (Builder $query) => $query->where('allows_sales', $request->boolean('allows_sales')))
            ->when($request->filled('manager_id'), fn (Builder $query) => $query->where('manager_id', $request->integer('manager_id')))
            ->when(
                is_array($place) && is_string($place['type'] ?? null) && is_numeric($place['id'] ?? null),
                fn (Builder $query) => $query->where(function (Builder $query) use ($place) {
                    foreach (collect(Inventory::locations()->within(Place::from($place)))->groupBy('type') as $type => $group) {
                        $query->orWhere(fn (Builder $q) => $q->where('place_type', $type)->whereIn('place_id', $group->pluck('id')));
                    }

                    $query->orWhereRaw('1 = 0');
                }),
            )
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return WarehouseResource::collection($warehouses);
    }

    public function store(WarehouseRequest $request, WarehouseService $warehouses): JsonResponse
    {
        $warehouse = $warehouses->create($request->validated());

        return (new WarehouseResource($this->loaded($warehouse)))->response()->setStatusCode(201);
    }

    public function show(Warehouse $warehouse): WarehouseResource
    {
        return new WarehouseResource($this->loaded($warehouse));
    }

    public function update(WarehouseRequest $request, Warehouse $warehouse, WarehouseService $warehouses): JsonResponse
    {
        $warehouses->update($warehouse, $request->validated());

        return response()->json(['message' => __('inventory::warehouses.updated')]);
    }

    public function destroy(Warehouse $warehouse, WarehouseService $warehouses): Response
    {
        $warehouses->delete($warehouse);

        return response()->noContent();
    }

    private function loaded(Warehouse $warehouse): Warehouse
    {
        return $warehouse->load('items')->loadCount(['balances' => fn (Builder $query) => $query->where('quantity', '>', 0)]);
    }
}
