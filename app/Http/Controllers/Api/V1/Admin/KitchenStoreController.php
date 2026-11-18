<?php

namespace App\Http\Controllers\Api\V1\Admin;

use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateKitchenStoreRequest;
use Illuminate\Http\JsonResponse;

/**
 * Which warehouse each branch's kitchen draws from.
 */
class KitchenStoreController extends Controller
{
    public function index(): JsonResponse
    {
        $stores = KitchenStore::query()->with('warehouse')->get()->keyBy('branch_id');

        $rows = Branch::query()->orderBy('id')->get()->map(
            fn (Branch $branch): array => $this->row($branch, $stores->get($branch->id)?->warehouse),
        );

        return response()->json(['data' => $rows->values()]);
    }

    public function update(UpdateKitchenStoreRequest $request, Branch $branch): JsonResponse
    {
        $store = KitchenStore::query()->updateOrCreate(
            ['branch_id' => $branch->id],
            ['warehouse_id' => (int) $request->validated('warehouse_id')],
        );

        return response()->json(['data' => $this->row($branch, $store->warehouse)]);
    }

    /**
     * @return array{branch: array{id: int, name: mixed}, warehouse: array{id: int, name: mixed}|null}
     */
    private function row(Branch $branch, ?Warehouse $warehouse): array
    {
        return [
            'branch' => ['id' => $branch->id, 'name' => $branch->translate('name')],
            'warehouse' => $warehouse ? ['id' => $warehouse->id, 'name' => $warehouse->translate('name')] : null,
        ];
    }
}
