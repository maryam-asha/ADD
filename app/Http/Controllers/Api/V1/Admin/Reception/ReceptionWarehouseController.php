<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Inventory\ReceptionWarehouse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The warehouses the desk may take stock from, so it can switch away from
 * the branch's primary one; the sale form offers the selling ones, the
 * consumption form all of them.
 */
class ReceptionWarehouseController extends Controller
{
    public function index(Request $request, ReceptionWarehouse $warehouses): JsonResponse
    {
        return response()->json(['data' => [
            // The open register's branch the list is limited to; null when no
            // register is open and the list holds every active warehouse, so
            // the desk must choose rather than take the first primary.
            'branch_id' => $warehouses->branchOf($request->user()),
            'warehouses' => $warehouses->options($request->user())->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'allows_sales' => $warehouse->allows_sales,
                'is_primary' => $warehouse->is_primary,
            ])->values(),
        ]]);
    }
}
