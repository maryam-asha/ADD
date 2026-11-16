<?php

namespace Add\Inventory\Assets\Http\Controllers;

use Add\Inventory\Assets\Http\Resources\AssetAssignmentResource;
use Add\Inventory\Assets\Models\AssetAssignment;
use Add\Inventory\Support\Http\PlaceFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Custody across assets: what one person or company holds (an employee
 * leaving hands back everything listed here), or every open custody.
 */
class CustodyController extends Controller
{
    /**
     * Filters: `custodian[type]` + `custodian[id]`; `open=0` includes what
     * was already returned; `place[type]` + `place[id]` (where the asset
     * sits, anywhere inside), `category_id`, `search` (its code or serial).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $custodian = $request->input('custodian');

        $assignments = AssetAssignment::query()
            ->with('asset.item')
            ->when(
                is_array($custodian) && is_string($custodian['type'] ?? null) && is_numeric($custodian['id'] ?? null),
                fn (Builder $query) => $query->where('custodian_type', $custodian['type'])->where('custodian_id', (int) $custodian['id']),
            )
            ->when($request->boolean('open', true), fn (Builder $query) => $query->whereNull('returned_at'))
            ->tap(fn (Builder $query) => PlaceFilter::throughAsset($query, $request))
            ->when($request->filled('search'), fn (Builder $query) => $query->whereHas('asset', fn (Builder $asset) => PlaceFilter::assetsMatching($asset, (string) $request->string('search'))))
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return AssetAssignmentResource::collection($assignments);
    }
}
